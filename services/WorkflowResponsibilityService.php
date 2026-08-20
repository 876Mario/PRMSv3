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

    /** Normalized (compacted, lower-case, no punctuation) branch name keys. */
    private const BRANCH_ANALYTICAL_ADVISORY = 'analyticaladvisory';
    private const BRANCH_HRMA                = 'hrma';
    private const BRANCH_EXECUTIVE           = 'executive';

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // -----------------------------------------------------------------------
    // Branch-name normalization
    // -----------------------------------------------------------------------

    /**
     * Normalize a branch name into a compact comparison key so that
     * capitalization, spacing, "&" vs "and", and a trailing "Branch" word
     * do not cause a mismatch. Examples that all normalize to the same key:
     *   "Analytical & Advisory", "Analytical and Advisory Branch"
     *   "HRM&A", "HRMA", "HRM & A Branch"
     *   "Executive Branch", "Executive"
     */
    public static function normalizeBranchName(?string $name): string
    {
        $lower = strtolower(trim((string) $name));
        preg_match_all('/[a-z0-9]+/', $lower, $matches);
        $tokens = array_filter($matches[0], static fn(string $t) => !in_array($t, ['and', 'branch'], true));

        return implode('', $tokens);
    }

    /**
     * Resolve the responsible role for the DIRECTOR_APPROVED stage based on
     * the requestor's branch. Unrecognized/unknown branches fall back to
     * Director HRM&A per the SOP default.
     */
    public static function resolveDirectorApprovedRole(?string $branchName): string
    {
        switch (self::normalizeBranchName($branchName)) {
            case self::BRANCH_ANALYTICAL_ADVISORY:
                return 'Deputy Government Chemist';
            case self::BRANCH_HRMA:
                return 'Director HRM&A';
            case self::BRANCH_EXECUTIVE:
                return 'HOD';
            default:
                return 'Director HRM&A';
        }
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
     *   assigned_officers:   array<int, array{role:string, name:?string}>,
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
            'assigned_officers'  => [],
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

        // Some stages have a responsible-officer set that cannot be expressed
        // as a single static role (it depends on the requestor's branch, or
        // it legitimately involves more than one officer). Resolve those here.
        $specs = $this->getDynamicOfficerSpecs($workflowType, $stageStatus, $branchId);

        if (!empty($specs)) {
            $result['is_configured'] = true;

            $officers = $this->resolveOfficers($specs, $request, $branchId, $currentUserRole);
            $result['assigned_officers'] = $officers;
            $result['responsible_role']  = implode(', ', array_values(array_unique(array_column($officers, 'role'))));

            $names = array_values(array_filter(array_column($officers, 'name'), static fn($n) => $n !== null));
            $result['assigned_user'] = $names !== [] ? implode(', ', array_unique($names)) : null;
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
        } elseif ($result['is_configured'] && empty($specs)) {
            // For pending stages with a single static role, try to find the
            // unique role-holder in this branch.
            $assignedUser = $this->findRoleUser(
                $result['responsible_role'],
                $branchId,
                $currentUserRole
            );
            if ($assignedUser !== null) {
                $result['assigned_user'] = $assignedUser;
                $result['source_type']   = 'Assigned to';
            }
        } elseif (!empty($specs)) {
            $result['source_type'] = $result['assigned_user'] !== null ? 'Assigned to' : 'Assigned by job title';
        }

        return $result;
    }

    // -----------------------------------------------------------------------
    // Dynamic (branch-based / multi-officer) responsibility resolution
    // -----------------------------------------------------------------------

    /**
     * Return the officer "specs" that must be resolved dynamically for a
     * given workflow type + stage, instead of using a fixed static role.
     *
     * Each spec may contain:
     *   role           Role name to search for (roles.name)
     *   label          Display label (defaults to role)
     *   requestor      true => resolve via request['created_by'] instead of role lookup
     *   branch_scoped  true => restrict the role lookup to the request's branch
     *   fallback_role  Role to try when the primary role/user cannot be found
     *
     * @return array<int, array<string, mixed>>
     */
    private function getDynamicOfficerSpecs(string $workflowType, string $stageStatus, int $branchId): array
    {
        $isProcurementLike = in_array($workflowType, ['REGULAR', 'SERVICE_CONTRACT'], true);

        if ($stageStatus === 'DIRECTOR_APPROVED' && $isProcurementLike) {
            $branchName = $this->getBranchName($branchId);
            $role       = self::resolveDirectorApprovedRole($branchName);

            return [[
                'role'          => $role,
                'branch_scoped' => ($role === 'HOD'),
            ]];
        }

        if ($stageStatus === 'HOD_APPROVED' && $isProcurementLike) {
            // Normally the branch's own HOD approves. Branches without a
            // dedicated HOD (e.g. the Government Chemist's own department)
            // fall back to the Government Chemist (Deputy Government Chemist).
            return [[
                'role'          => 'HOD',
                'label'         => 'HOD',
                'branch_scoped' => true,
                'fallback_role' => 'Deputy Government Chemist',
            ]];
        }

        if ($stageStatus === 'FUNDS_VERIFIED' && $isProcurementLike) {
            return [[
                'role'          => 'Finance Officer',
                'branch_scoped' => true,
            ]];
        }

        if (in_array($stageStatus, ['QUOTE_REVIEW_PENDING', 'QUOTE_APPROVED'], true) && $workflowType === 'REGULAR') {
            return [
                ['role' => 'Requestor', 'requestor' => true],
                ['role' => 'HOD', 'label' => 'Branch Head', 'branch_scoped' => true],
            ];
        }

        if (in_array($stageStatus, ['RFQ_LETTER_AVAILABLE', 'PO_PENDING'], true) && $workflowType === 'REGULAR') {
            return [
                ['role' => 'Procurement Officer', 'branch_scoped' => false],
                ['role' => 'Director Procurement', 'label' => 'Director of Procurement', 'branch_scoped' => false],
            ];
        }

        return [];
    }

    /**
     * Resolve a list of officer specs into concrete (role label, user name)
     * pairs, de-duplicating officers who resolve to the same person.
     *
     * @param array<int, array<string, mixed>> $specs
     * @return array<int, array{role:string, name:?string}>
     */
    private function resolveOfficers(array $specs, array $request, int $branchId, string $currentUserRole): array
    {
        $officers = [];
        $seenNames = [];
        $seenUnresolvedLabels = [];

        foreach ($specs as $spec) {
            $label = $spec['label'] ?? $spec['role'];
            $name  = null;

            if (!empty($spec['requestor'])) {
                $requestorId = (int) ($request['created_by'] ?? $request['requested_by'] ?? 0);
                $name = $requestorId > 0 ? $this->getUserNameById($requestorId) : null;
            } else {
                $branchScoped = (bool) ($spec['branch_scoped'] ?? false);
                $name = $this->findRoleUser($spec['role'], $branchId, $currentUserRole, $branchScoped);

                if ($name === null && !empty($spec['fallback_role'])) {
                    $fallbackName = $this->findRoleUser($spec['fallback_role'], 0, $currentUserRole, false);
                    if ($fallbackName !== null) {
                        $label = $spec['fallback_role'];
                        $name  = $fallbackName;
                    }
                }
            }

            if ($name !== null) {
                // Avoid showing the same person twice under two different
                // labels (e.g. a requestor who is also the branch head).
                $key = strtolower($name);
                if (isset($seenNames[$key])) {
                    continue;
                }
                $seenNames[$key] = true;
            } else {
                // Avoid repeating an identical "not yet assigned" row for the
                // same unresolved role label.
                $labelKey = strtolower($label);
                if (isset($seenUnresolvedLabels[$labelKey])) {
                    continue;
                }
                $seenUnresolvedLabels[$labelKey] = true;
            }

            $officers[] = ['role' => $label, 'name' => $name];
        }

        return $officers;
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
                    // Approved by the branch's HOD; falls back to the Government
                    // Chemist for branches (e.g. the GC's own department) that
                    // have no dedicated HOD. Resolved dynamically per branch.
                    'role'   => 'HOD',
                    'action' => 'Review the request as branch head and approve or decline.',
                ],
                'FUNDS_VERIFIED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Verify that sufficient funds are available for this request.',
                ],
                'DIRECTOR_APPROVED' => [
                    // Final approver depends on the requestor's branch:
                    // Analytical & Advisory -> Deputy Government Chemist,
                    // HRM&A -> Director HRM&A, Executive -> HOD,
                    // any other branch -> Director HRM&A (default). Resolved
                    // dynamically per branch.
                    'role'   => 'Director HRM&A',
                    'action' => 'Review and provide final approval for this request.',
                ],
                'GC_APPROVED' => [
                    'role'   => 'Procurement Officer',
                    'action' => 'Generate and issue the RFQ letter to shortlisted vendors.',
                ],
                'RFQ_LETTER_AVAILABLE' => [
                    'role'   => 'Procurement Officer, Director of Procurement',
                    'action' => 'Generate and issue the RFQ letter to shortlisted vendors.',
                ],
                'QUOTE_REVIEW_PENDING' => [
                    'role'   => 'Requestor, Branch Head',
                    'action' => 'Review submitted quotations and select the preferred vendor.',
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
                    'role'   => 'Requestor, Branch Head',
                    'action' => 'Quote selected; awaiting finance to verify funds and create the commitment.',
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
                    'role'   => 'Procurement Officer, Director of Procurement',
                    'action' => 'Generate and issue the purchase order to the awarded vendor.',
                ],
                'PO_APPROVED' => [
                    'role'   => 'Accounts Officer',
                    'action' => 'Upload and record the vendor invoice once goods are received.',
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
                    // Approved by the branch's HOD; falls back to the Government
                    // Chemist for branches without a dedicated HOD. Resolved
                    // dynamically per branch.
                    'role'   => 'HOD',
                    'action' => 'Review the request as branch head and approve or decline.',
                ],
                'DIRECTOR_APPROVED' => [
                    // Final approver depends on the requestor's branch (see
                    // REGULAR workflow docblock above). Resolved dynamically.
                    'role'   => 'Director HRM&A',
                    'action' => 'Provide final approval for this service contract.',
                ],
                'GC_APPROVED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Verify funds and create the financial commitment.',
                ],
                'FUNDS_VERIFIED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Create the financial commitment for the service contract.',
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
            'HOD_APPROVED'      => ['HOD', 'Branch Head'],
            'FUNDS_VERIFIED'    => ['Finance Officer'],
            'DIRECTOR_APPROVED' => ['Director HRM&A'],
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
     * @param string    $role            Job title / role name from the static map
     * @param int       $branchId        Branch to scope the search
     * @param string    $currentUserRole Viewer role (reserved for future stricter gates)
     * @param bool|null $branchScoped    true = restrict to $branchId, false = organisation-wide,
     *                                   null = auto-detect from a legacy fixed role list
     */
    private function findRoleUser(string $role, int $branchId, string $currentUserRole, ?bool $branchScoped = null): ?string
    {
        if ($branchScoped === null) {
            // Legacy auto-detection, kept for callers that do not specify scope explicitly.
            $branchScopedRoles = ['HOD', 'Head of Department', 'Branch Head', 'Finance Officer'];
            $branchScoped = in_array($role, $branchScopedRoles, true);
        }

        if ($branchScoped) {
            if ($branchId <= 0) {
                return null;
            }

            $stmt = $this->pdo->prepare(
                'SELECT u.full_name
                   FROM users u
                   JOIN roles r ON r.id = u.role_id
                  WHERE r.name = ?
                    AND u.is_active = 1
                    AND u.branch_id = ?
                  LIMIT 2'
            );
            $stmt->execute([$role, $branchId]);
        } else {
            // Organisation-wide roles (Director, DGC, Procurement Officer, etc.)
            $stmt = $this->pdo->prepare(
                'SELECT u.full_name
                   FROM users u
                   JOIN roles r ON r.id = u.role_id
                  WHERE r.name = ?
                    AND u.is_active = 1
                  LIMIT 2'
            );
            $stmt->execute([$role]);
        }

        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Only return a name when there is exactly one match — ambiguous
        // assignments fall back to role-based display.
        return (count($rows) === 1) ? $rows[0] : null;
    }

    /**
     * Resolve a user's full name by user_id. Returns null when the user does
     * not exist, is inactive, or the id is invalid. No e-mail is returned.
     */
    private function getUserNameById(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT full_name FROM users WHERE user_id = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$userId]);
        $name = $stmt->fetchColumn();

        return $name !== false ? (string) $name : null;
    }

    /**
     * Resolve a branch's display name by branch_id. Returns null when the
     * branch cannot be found or the branches table is unavailable.
     */
    private function getBranchName(int $branchId): ?string
    {
        if ($branchId <= 0) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT branch_name FROM branches WHERE branch_id = ? LIMIT 1'
            );
            $stmt->execute([$branchId]);
            $name = $stmt->fetchColumn();

            return $name !== false ? (string) $name : null;
        } catch (\Throwable $e) {
            // Table may not exist in some contexts (e.g. tests); resolve to
            // null so callers fall back to the default responsibility.
            return null;
        }
    }
}
