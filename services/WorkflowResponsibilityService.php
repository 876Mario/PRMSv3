<?php

/**
 * WorkflowResponsibilityService
 *
 * Resolves which role or user is responsible for each pipeline stage of a
 * given request.  All data is computed server-side from:
 *   - A static responsibility map keyed by workflow type + stage status
 *   - The request_approvals table (for completed-stage completers)
 *   - The users table (to resolve role → name when a unique assignee exists)
 *
 * No client-supplied parameters are trusted.  The caller supplies the already-
 * authenticated request row and role; this class enforces what may be returned
 * based on those values.
 *
 * Responsibility data is intentionally minimal: we return the responsible job
 * title, an optional named user, the source type, and an action description.
 * E-mail addresses are never returned.
 */
class WorkflowResponsibilityService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Compute the responsibility record for one pipeline stage of a request.
     *
     * @param array  $request          Full row from procurement_requests (or
     *                                 reimbursement/petty-cash equivalent).
     * @param string $stageStatus      Uppercase status key, e.g. 'HOD_APPROVED'.
     * @param array  $requestApprovals All rows from request_approvals for this
     *                                 request, indexed numerically, as returned
     *                                 by a PDO query.
     * @param string $currentUserRole  Session role_name of the viewer.
     * @param bool   $isCompleted      True when the stage is behind the current
     *                                 status in the pipeline.
     *
     * @return array{
     *   responsible_role:    string,
     *   assigned_user:       string|null,
     *   source_type:         string,
     *   action_description:  string,
     *   completer_name:      string|null,
     *   completer_role:      string|null,
     *   is_configured:       bool,
     * }
     */
    public function getStageResponsibility(
        array  $request,
        string $stageStatus,
        array  $requestApprovals,
        string $currentUserRole,
        bool   $isCompleted = false
    ): array {

        $workflowType = strtoupper($request['request_type'] ?? 'REGULAR');
        $branchId     = (int) ($request['branch_id'] ?? 0);

        $map    = self::getStaticResponsibilityMap();
        $entry  = $map[$workflowType][$stageStatus]
               ?? $map['REGULAR'][$stageStatus]
               ?? null;

        $result = [
            'responsible_role'   => '',
            'assigned_user'      => null,
            'assigned_users'     => [],
            'source_type'        => 'Awaiting system assignment',
            'action_description' => '',
            'completer_name'     => null,
            'completer_role'     => null,
            'is_configured'      => false,
        ];

        if ($entry !== null) {
            $result['responsible_role']   = $entry['role'];
            $result['action_description'] = $entry['action'];
            $result['is_configured']      = true;
            $result['source_type']        = 'Assigned by job title';
        }

        // For completed stages: resolve who actually completed it
        if ($isCompleted) {
            $completerData = $this->resolveCompleter(
                $workflowType,
                $stageStatus,
                $requestApprovals,
                $currentUserRole
            );
            if ($completerData !== null) {
                $result['completer_name'] = $completerData['name'];
                $result['completer_role'] = $completerData['role'];
            }
            return $result;
        }

        // For pending/current stages: certain stages have dynamic, branch- or
        // request-aware responsibility rules that override the static map.
        // These take precedence over the generic single-role lookup below.
        $officers = $this->resolveDynamicStageOfficers($stageStatus, $request, $branchId);

        if ($officers !== null) {
            [$roles, $names] = $this->splitOfficers($officers);

            if (!empty($roles)) {
                $result['responsible_role'] = implode(' / ', $roles);
                $result['is_configured']    = true;
            }

            $result['assigned_users'] = $names;
            if (!empty($names)) {
                $result['assigned_user'] = implode(', ', $names);
                $result['source_type']   = 'Assigned to';
            } else {
                $result['source_type'] = 'Assigned by job title';
            }
        } elseif ($result['is_configured']) {
            // Generic path: try to find the unique role-holder in this branch
            $assignedUser = $this->findRoleUser(
                $result['responsible_role'],
                $branchId,
                $currentUserRole
            );
            if ($assignedUser !== null) {
                $result['assigned_user']  = $assignedUser;
                $result['assigned_users'] = [$assignedUser];
                $result['source_type']    = 'Assigned to';
            }
        }

        return $result;
    }

    /**
     * Resolve the officer(s) responsible for stages whose ownership depends on
     * the request's branch or on the requestor, rather than on a single fixed
     * role.  Returns null when the stage has no dynamic rule (caller should
     * fall back to the static single-role map).
     *
     * @return array<int, array{role:string, name:?string}>|null
     */
    private function resolveDynamicStageOfficers(string $stageStatus, array $request, int $branchId): ?array
    {
        switch ($stageStatus) {
            case 'DIRECTOR_APPROVED':
                return [$this->resolveDirectorApprovedOfficer($branchId)];

            case 'HOD_APPROVED':
                return [$this->resolveHodApprovedOfficer($branchId)];

            case 'FUNDS_VERIFIED':
            case 'COMMITMENTS_PENDING':
            case 'INVOICE_RECEIVED':
                return [$this->makeOfficer('Finance Officer', $this->findUserByRole(['Finance Officer'], $branchId))];

            case 'QUOTE_REVIEW_PENDING':
            case 'QUOTE_APPROVED':
                return $this->resolveRequestorAndBranchHead($request, $branchId);

            case 'RFQ_LETTER_AVAILABLE':
            case 'PO_PENDING':
            case 'PO_APPROVED':
                return $this->resolveProcurementAndDirector();

            default:
                return null;
        }
    }

    /**
     * Split a list of ['role' => ..., 'name' => ...] officers into two
     * deduplicated arrays: distinct role labels, and distinct resolved names.
     *
     * @param array<int, array{role:string, name:?string}> $officers
     * @return array{0: string[], 1: string[]}
     */
    private function splitOfficers(array $officers): array
    {
        $roles = [];
        $names = [];

        foreach ($officers as $officer) {
            $role = trim((string) ($officer['role'] ?? ''));
            if ($role !== '' && !in_array($role, $roles, true)) {
                $roles[] = $role;
            }

            $name = $officer['name'] ?? null;
            if ($name !== null) {
                $name = trim((string) $name);
                if ($name !== '' && !in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        return [$roles, $names];
    }

    /** @return array{role:string, name:?string} */
    private function makeOfficer(string $role, ?string $name): array
    {
        return ['role' => $role, 'name' => $name];
    }

    /**
     * DIRECTOR_APPROVED ("Director Approved") responsibility.
     *
     * Branch-based mapping (normalized branch name comparison):
     *   - Analytical and Advisory Branch -> Deputy Government Chemist
     *   - HRM&A Branch (incl. "HRMA" variant) -> Director HRM&A
     *   - Executive Branch -> HOD
     *   - Any other / unrecognized branch -> Director HRM&A (fallback)
     */
    private function resolveDirectorApprovedOfficer(int $branchId): array
    {
        $branchClass = self::classifyBranch($this->getBranchName($branchId));

        switch ($branchClass) {
            case 'ANALYTICAL_ADVISORY':
                $role         = 'Deputy Government Chemist';
                $scopeBranch  = null; // organisation-wide role
                break;
            case 'EXECUTIVE':
                $role         = 'HOD';
                $scopeBranch  = $branchId;
                break;
            case 'HRMA':
            default:
                $role         = 'Director HRM&A';
                $scopeBranch  = null; // organisation-wide role
                break;
        }

        $name = $this->findUserByRole([$role], $scopeBranch);

        return $this->makeOfficer($role, $name);
    }

    /**
     * HOD_APPROVED ("HOD Approved") responsibility.
     *
     * The Government Chemist acts directly as HOD for the Executive Branch;
     * every other branch is approved by its own Government Chemist-designated
     * HOD (equivalently, "Branch Head").
     */
    private function resolveHodApprovedOfficer(int $branchId): array
    {
        $branchClass = self::classifyBranch($this->getBranchName($branchId));

        if ($branchClass === 'EXECUTIVE') {
            $role = 'Government Chemist';
            $name = $this->findUserByRole(['Government Chemist'], null);
        } else {
            $role = 'HOD';
            $name = $this->findUserByRole(['HOD', 'Branch Head'], $branchId);
        }

        return $this->makeOfficer($role, $name);
    }

    /**
     * QUOTE_REVIEW_PENDING ("Quote Review") and QUOTE_APPROVED ("Quote
     * Selected") responsibility: the requestor together with the applicable
     * branch head for the requestor's branch.
     *
     * @return array<int, array{role:string, name:?string}>
     */
    private function resolveRequestorAndBranchHead(array $request, int $branchId): array
    {
        $requestorId = (int) ($request['created_by'] ?? $request['requestor_id'] ?? $request['user_id'] ?? 0);
        $requestorName = $requestorId > 0 ? $this->getUserFullNameById($requestorId) : null;

        $branchHeadName = $this->findUserByRole(['HOD', 'Branch Head'], $branchId);

        return [
            $this->makeOfficer('Requestor', $requestorName),
            $this->makeOfficer('Branch Head', $branchHeadName),
        ];
    }

    /**
     * RFQ_LETTER_AVAILABLE ("RFQ Letters") and PO_PENDING/PO_APPROVED
     * ("Purchase Order") responsibility: Procurement Officer and Director of
     * Procurement, organisation-wide roles.
     *
     * @return array<int, array{role:string, name:?string}>
     */
    private function resolveProcurementAndDirector(): array
    {
        return [
            $this->makeOfficer('Procurement Officer', $this->findUserByRole(['Procurement Officer'], null)),
            $this->makeOfficer('Director Procurement', $this->findUserByRole(['Director Procurement', 'Director of Procurement'], null)),
        ];
    }

    /**
     * Batch-compute responsibility for every stage in a pipeline.
     *
     * @param array  $pipelineStages   Ordered stage keys → ['label', 'icon']
     * @param array  $request          Full request row
     * @param string $currentStatus    Current request status
     * @param array  $requestApprovals Rows from request_approvals
     * @param string $currentUserRole  Viewer's role
     *
     * @return array Keyed by stage status → responsibility array
     */
    public function getPipelineResponsibility(
        array  $pipelineStages,
        array  $request,
        string $currentStatus,
        array  $requestApprovals,
        string $currentUserRole
    ): array {

        $stageKeys  = array_keys($pipelineStages);
        $currentIdx = array_search($currentStatus, $stageKeys, true);

        $result = [];
        foreach ($stageKeys as $idx => $stageKey) {
            $isCompleted = ($currentIdx !== false && $idx < $currentIdx);
            $result[$stageKey] = $this->getStageResponsibility(
                $request,
                $stageKey,
                $requestApprovals,
                $currentUserRole,
                $isCompleted
            );
        }
        return $result;
    }

    // -----------------------------------------------------------------------
    // Static responsibility map
    // -----------------------------------------------------------------------

    /**
     * Returns the canonical static map:
     *   workflowType → stageStatus → ['role', 'action']
     *
     * Role names use the human-readable job-title strings that match the
     * roles.name column — never numeric IDs.
     */
    public static function getStaticResponsibilityMap(): array
    {
        return [
            // ----------------------------------------------------------------
            // REGULAR procurement
            // ----------------------------------------------------------------
            'REGULAR' => [
                'DRAFT' => [
                    'role'   => 'Requestor',
                    'action' => 'Complete all required fields and submit the procurement request.',
                ],
                'SUBMITTED' => [
                    'role'   => 'Head of Department',
                    'action' => 'Review the request details and approve or decline.',
                ],
                'HOD_APPROVED' => [
                    'role'   => 'HOD',
                    'action' => 'Provide HOD-level approval for this request (the Government Chemist approves directly for the Executive Branch).',
                ],
                'FUNDS_VERIFIED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Verify that sufficient funds are available for this request.',
                ],
                'DIRECTOR_APPROVED' => [
                    'role'   => 'Director HRM&A',
                    'action' => 'Provide director-level approval for this request (Deputy Government Chemist for Analytical and Advisory Branch, Director HRM&A for HRM&A Branch, HOD for Executive Branch).',
                ],
                'GC_APPROVED' => [
                    'role'   => 'Procurement Officer',
                    'action' => 'Generate and issue the RFQ letter to shortlisted vendors.',
                ],
                'RFQ_LETTER_AVAILABLE' => [
                    'role'   => 'Procurement Officer / Director Procurement',
                    'action' => 'Procurement Officer collects vendor quotations; Director of Procurement oversees the RFQ letters for this request.',
                ],
                'QUOTE_REVIEW_PENDING' => [
                    'role'   => 'Requestor / Branch Head',
                    'action' => 'Requestor and branch head review submitted quotations and select the preferred vendor.',
                ],
                'QUOTE_SPEC_REVIEW_PENDING' => [
                    'role'   => 'Procurement Officer',
                    'action' => 'Review quotations against technical specifications.',
                ],
                'QUOTE_SPEC_REVIEW_APPROVED' => [
                    'role'   => 'Branch Head',
                    'action' => 'Approve the specification-reviewed quotation.',
                ],
                'QUOTE_BRANCH_HEAD_APPROVAL_PENDING' => [
                    'role'   => 'Branch Head',
                    'action' => 'Provide branch head approval for the selected quotation.',
                ],
                'QUOTE_APPROVED' => [
                    'role'   => 'Requestor / Branch Head',
                    'action' => 'Requestor and branch head confirm the selected quotation.',
                ],
                'COMMITMENTS_PENDING' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Create the financial commitment for the awarded vendor.',
                ],
                'COMMITMENT_APPROVED' => [
                    'role'   => 'Procurement Officer',
                    'action' => 'Generate the purchase order from the approved commitment.',
                ],
                'COMMITMENT_DECLINED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Resolve the funding issue and resubmit the commitment.',
                ],
                'PO_PENDING' => [
                    'role'   => 'Procurement Officer / Director Procurement',
                    'action' => 'Procurement Officer generates the purchase order; Director of Procurement provides approval.',
                ],
                'PO_APPROVED' => [
                    'role'   => 'Procurement Officer / Director Procurement',
                    'action' => 'Procurement Officer generates the purchase order; Director of Procurement provides approval.',
                ],
                'INVOICE_RECEIVED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Process payment and mark the request as complete.',
                ],
                'PROCUREMENT_STAGE' => [
                    'role'   => 'Procurement Officer',
                    'action' => 'Manage the open-tender procurement stage.',
                ],
                'EVALUATION_STAGE' => [
                    'role'   => 'Procurement Officer',
                    'action' => 'Evaluate submitted tenders and prepare evaluation report.',
                ],
                'COMMITTEE_RECOMMENDED' => [
                    'role'   => 'Procurement Committee',
                    'action' => 'Review committee recommendation and provide approval.',
                ],
                'AWARDED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Create the financial commitment for the awarded vendor.',
                ],
                'COMPLETED' => [
                    'role'   => 'System',
                    'action' => 'This request has been fully processed and closed.',
                ],
                'DECLINED' => [
                    'role'   => 'Head of Department',
                    'action' => 'This request was declined. Contact the requestor if needed.',
                ],
                'CANCELLED' => [
                    'role'   => 'Admin / Requestor',
                    'action' => 'This request was cancelled.',
                ],
                'PAUSED' => [
                    'role'   => 'Admin',
                    'action' => 'This request has been paused by an administrator.',
                ],
            ],

            // ----------------------------------------------------------------
            // REIMBURSEMENT
            // ----------------------------------------------------------------
            'REIMBURSEMENT' => [
                'DRAFT' => [
                    'role'   => 'Requestor',
                    'action' => 'Complete all required fields and submit the reimbursement form.',
                ],
                'SUBMITTED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Verify that sufficient funds are available for this reimbursement.',
                ],
                'FUNDS_VERIFIED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Review submitted invoices and verify the amounts claimed.',
                ],
                'INVOICE_SUBMITTED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Verify the invoice details and confirm accuracy.',
                ],
                'INVOICE_VERIFIED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Approve the reimbursement after invoice verification.',
                ],
                'APPROVED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Process the reimbursement payment to the requestor.',
                ],
                'REIMBURSED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Confirm receipt and mark this reimbursement as complete.',
                ],
                'COMPLETED' => [
                    'role'   => 'System',
                    'action' => 'This reimbursement has been fully processed and closed.',
                ],
                'DECLINED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'This reimbursement was declined.',
                ],
            ],

            // ----------------------------------------------------------------
            // PETTY_CASH
            // ----------------------------------------------------------------
            'PETTY_CASH' => [
                'DRAFT' => [
                    'role'   => 'Requestor',
                    'action' => 'Complete all required fields and submit the petty cash request.',
                ],
                'SUBMITTED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Verify that petty cash funds are available for this request.',
                ],
                'FUNDS_VERIFIED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Review and authorize the petty cash disbursement.',
                ],
                'FINANCE_AUTHORIZED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Disburse the approved petty cash amount to the requestor.',
                ],
                'DISBURSED' => [
                    'role'   => 'Requestor',
                    'action' => 'Submit purchase receipts and reconciliation documentation.',
                ],
                'PENDING_RECONCILIATION' => [
                    'role'   => 'Procurement Officer',
                    'action' => 'Verify submitted purchase documentation.',
                ],
                'PROCUREMENT_VERIFIED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Confirm reconciliation and close this petty cash request.',
                ],
                'RECONCILIATION_DISCREPANCY' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Review the reconciliation discrepancy and resolve.',
                ],
                'REVIEWED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Confirm the reviewed status and mark as complete.',
                ],
                'COMPLETED' => [
                    'role'   => 'System',
                    'action' => 'This petty cash request has been fully processed and closed.',
                ],
                'DECLINED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'This petty cash request was declined.',
                ],
            ],

            // ----------------------------------------------------------------
            // SERVICE_CONTRACT
            // ----------------------------------------------------------------
            'SERVICE_CONTRACT' => [
                'DRAFT' => [
                    'role'   => 'Requestor',
                    'action' => 'Complete all required fields and submit the service contract request.',
                ],
                'SUBMITTED' => [
                    'role'   => 'Branch Head',
                    'action' => 'Review and approve this service contract request.',
                ],
                'HOD_APPROVED' => [
                    'role'   => 'HOD',
                    'action' => 'Provide HOD-level approval for this service contract (the Government Chemist approves directly for the Executive Branch).',
                ],
                'DIRECTOR_APPROVED' => [
                    'role'   => 'Director HRM&A',
                    'action' => 'Provide director-level approval for this service contract (Deputy Government Chemist for Analytical and Advisory Branch, Director HRM&A for HRM&A Branch, HOD for Executive Branch).',
                ],
                'GC_APPROVED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Verify funds and create the financial commitment.',
                ],
                'FUNDS_VERIFIED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Verify that sufficient funds are available for this service contract.',
                ],
                'COMMITMENT_APPROVED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Upload and record the vendor invoice when received.',
                ],
                'INVOICE_RECEIVED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Process payment and mark the contract as complete.',
                ],
                'COMPLETED' => [
                    'role'   => 'System',
                    'action' => 'This service contract has been fully processed and closed.',
                ],
                'DECLINED' => [
                    'role'   => 'Branch Head',
                    'action' => 'This service contract was declined.',
                ],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Find who completed a given stage by looking at the request_approvals table.
     *
     * For REGULAR / SERVICE_CONTRACT workflows the stage name encodes the
     * approver role (HOD_APPROVED → HOD, DIRECTOR_APPROVED → Director HRM&A).
     * For REIMBURSEMENT and PETTY_CASH a single Finance Officer role appears
     * across all stages; we match any approved Finance Officer record rather
     * than trying to derive a stage key from the role name.
     *
     * @param string $workflowType     REGULAR | REIMBURSEMENT | PETTY_CASH | SERVICE_CONTRACT
     * @param string $stageStatus      Stage status key to resolve
     * @param array  $requestApprovals All approval rows for the request
     * @param string $currentUserRole  Viewer's role (reserved for future stricter gates)
     *
     * @return array{name:string, role:string}|null
     */
    private function resolveCompleter(
        string $workflowType,
        string $stageStatus,
        array  $requestApprovals,
        string $currentUserRole
    ): ?array {

        // Map stage-status keys to the role(s) that drive that stage completion.
        // Applies to REGULAR and SERVICE_CONTRACT where each approval stage maps
        // to a distinct named role.
        $stageToRoles = [
            'HOD_APPROVED'      => ['HOD', 'Branch Head', 'Government Chemist'],
            'FUNDS_VERIFIED'    => ['Finance Officer'],
            'DIRECTOR_APPROVED' => ['Director HRM&A', 'Deputy Government Chemist', 'HOD'],
            'GC_APPROVED'       => ['Deputy Government Chemist', 'Government Chemist'],
            'AWARDED'           => ['Deputy Government Chemist', 'Government Chemist'],
        ];

        // For REIMBURSEMENT / PETTY_CASH Finance Officer owns every stage,
        // so any approved Finance Officer record is the completer.
        if (in_array($workflowType, ['REIMBURSEMENT', 'PETTY_CASH'], true)) {
            $expectedRoles = ['Finance Officer'];
        } else {
            $expectedRoles = $stageToRoles[$stageStatus] ?? [];
        }

        if (empty($expectedRoles)) {
            return null;
        }

        // Fetch completer name — no email or sensitive fields.
        foreach ($requestApprovals as $approval) {
            $approvalRole = $approval['role'] ?? '';
            $status       = strtolower($approval['status'] ?? '');
            $approvedBy   = (int)($approval['approved_by'] ?? 0);

            if ($status !== 'approved' || $approvedBy === 0) {
                continue;
            }
            if (!in_array($approvalRole, $expectedRoles, true)) {
                continue;
            }

            $stmt = $this->pdo->prepare(
                'SELECT u.full_name FROM users u WHERE u.user_id = ? LIMIT 1'
            );
            $stmt->execute([$approvedBy]);
            $name = $stmt->fetchColumn();

            if ($name !== false) {
                return [
                    'name' => (string) $name,
                    'role' => $approvalRole,
                ];
            }
        }

        return null;
    }

    /**
     * Look up a unique user who holds a given job title/role within the
     * specified branch.  Returns the user's full name only when exactly one
     * active user matches; returns null otherwise (ambiguous or no match).
     *
     * Authorization: role-holder lookup is permitted for any authenticated
     * viewer of the request.  E-mail addresses are never returned.
     *
     * @param string $role            Job title / role name from the static map
     * @param int    $branchId        Branch to scope the search
     * @param string $currentUserRole Viewer role (reserved for future stricter gates)
     */
    private function findRoleUser(string $role, int $branchId, string $currentUserRole): ?string
    {
        // Scope search: roles that are branch-specific should match on branch_id
        $branchScopedRoles = ['HOD', 'Head of Department', 'Branch Head', 'Finance Officer'];

        $scopeBranch = (in_array($role, $branchScopedRoles, true) && $branchId > 0)
            ? $branchId
            : null;

        return $this->findUserByRole([$role], $scopeBranch);
    }

    /**
     * Find the unique active user holding one of the given (equivalent) role
     * names, optionally scoped to a branch.  Returns the user's full name
     * only when exactly one distinct active user matches; returns null when
     * no user matches or when the match is ambiguous (more than one
     * candidate) — callers must display the role alone in that case.
     *
     * Never returns e-mail or other sensitive fields.
     *
     * @param string[] $roleNames       One or more interchangeable role names
     *                                  (e.g. ['HOD', 'Branch Head']).
     * @param int|null  $branchId       Branch to scope the search to, or null
     *                                  for organisation-wide roles.
     */
    private function findUserByRole(array $roleNames, ?int $branchId): ?string
    {
        $roleNames = array_values(array_unique(array_filter(
            $roleNames,
            static fn($r) => is_string($r) && $r !== ''
        )));

        if (empty($roleNames)) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($roleNames), '?'));

        $sql = 'SELECT DISTINCT u.user_id, u.full_name
                  FROM users u
                  JOIN roles r ON r.id = u.role_id
                 WHERE r.name IN (' . $placeholders . ')
                   AND u.is_active = 1';
        $params = $roleNames;

        if ($branchId !== null && $branchId > 0) {
            $sql .= ' AND u.branch_id = ?';
            $params[] = $branchId;
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return null;
        }

        // Deduplicate by user_id in case multiple equivalent role rows match
        // the same person.
        $byUser = [];
        foreach ($rows as $row) {
            $byUser[$row['user_id']] = $row['full_name'];
        }

        // Only return a name when there is exactly one distinct match —
        // ambiguous assignments fall back to role-based display.
        return (count($byUser) === 1) ? (string) reset($byUser) : null;
    }

    /**
     * Look up a user's full name by ID.  Returns null when the user does not
     * exist or is inactive (a deleted/deactivated requestor, for example).
     */
    private function getUserFullNameById(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT full_name FROM users WHERE user_id = ? AND is_active = 1 LIMIT 1'
            );
            $stmt->execute([$userId]);
            $name = $stmt->fetchColumn();
        } catch (Exception $e) {
            return null;
        }

        return ($name !== false) ? (string) $name : null;
    }

    /**
     * Resolve a branch's display name from its ID.  Returns null when the
     * branch cannot be found (e.g. a deleted branch or missing table in a
     * lightweight test double), so callers gracefully fall back to the
     * default classification.
     */
    private function getBranchName(int $branchId): ?string
    {
        if ($branchId <= 0) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare('SELECT branch_name FROM branches WHERE branch_id = ? LIMIT 1');
            $stmt->execute([$branchId]);
            $name = $stmt->fetchColumn();
        } catch (Exception $e) {
            return null;
        }

        return ($name !== false) ? (string) $name : null;
    }

    /**
     * Normalize a branch name for comparison: lower-cased, "&" expanded to
     * "and", punctuation collapsed to single spaces, trimmed.  This makes
     * comparisons resilient to capitalization, spacing, and the "HRM&A" /
     * "HRMA" / "HRM and A" family of variants.
     */
    public static function normalizeBranchName(?string $name): string
    {
        $name = strtolower(trim((string) $name));
        $name = str_replace('&', ' and ', $name);
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? '';
        return trim(preg_replace('/\s+/', ' ', $name) ?? '');
    }

    /**
     * Classify a branch name into one of the categories used by the
     * branch-dependent responsibility rules.
     *
     * @return string One of: ANALYTICAL_ADVISORY | HRMA | EXECUTIVE | OTHER
     */
    public static function classifyBranch(?string $branchName): string
    {
        $normalized = self::normalizeBranchName($branchName);

        if ($normalized === '') {
            return 'OTHER';
        }

        if (str_contains($normalized, 'hrm')) {
            return 'HRMA';
        }

        if (str_contains($normalized, 'analytical') && str_contains($normalized, 'advisory')) {
            return 'ANALYTICAL_ADVISORY';
        }

        if (str_contains($normalized, 'executive')) {
            return 'EXECUTIVE';
        }

        return 'OTHER';
    }
}
