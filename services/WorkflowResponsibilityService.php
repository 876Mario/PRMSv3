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
            'responsible_role'    => '',
            'assigned_user'       => null,
            'source_type'         => 'Awaiting system assignment',
            'action_description'  => '',
            'completer_name'      => null,
            'completer_role'      => null,
            'is_configured'       => false,
            'responsible_officers'=> [],
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
        } elseif ($result['is_configured']) {
            // Some stages have a business-rule-driven set of responsible
            // officers (e.g. branch-dependent approvers, or multiple named
            // job titles). When such an override applies, it takes
            // precedence over the generic single-role lookup below.
            $officers = $this->resolveStageOfficers($workflowType, $stageStatus, $request);

            if ($officers === null) {
                // Generic path: try to find the unique role-holder in this branch
                $assignedUser = $this->findRoleUser(
                    $result['responsible_role'],
                    $branchId,
                    $currentUserRole
                );
                if ($assignedUser !== null) {
                    $result['assigned_user'] = $assignedUser;
                    $result['source_type']   = 'Assigned to';
                    $result['responsible_officers'] = [
                        ['role' => $result['responsible_role'], 'name' => $assignedUser],
                    ];
                } else {
                    $result['responsible_officers'] = [
                        ['role' => $result['responsible_role'], 'name' => null],
                    ];
                }
            } else {
                $officers = self::dedupeOfficers($officers);

                $result['responsible_officers'] = $officers;
                $result['responsible_role']     = implode(' / ', array_unique(array_column($officers, 'role')));

                $names = array_values(array_filter(array_unique(array_column($officers, 'name'))));
                if (!empty($names)) {
                    $result['assigned_user'] = implode(', ', $names);
                    $result['source_type']   = 'Assigned to';
                }
            }
        }

        return $result;
    }

    /**
     * Determine the (possibly branch-dependent, possibly multi-officer) set
     * of responsible officers for stages whose responsibility assignment is
     * governed by an explicit business rule rather than the generic
     * static-map single-role lookup.
     *
     * Returns null when no override applies for this stage — the caller
     * should fall back to the generic single-role lookup.
     *
     * @return array<int, array{role: string, name: string|null}>|null
     */
    private function resolveStageOfficers(string $workflowType, string $stageStatus, array $request): ?array
    {
        // Overrides only apply to procurement-style workflows that carry a
        // branch_id and go through the branch approval chain.
        if (!in_array($workflowType, ['REGULAR', 'SERVICE_CONTRACT'], true)) {
            return null;
        }

        $branchId   = (int) ($request['branch_id'] ?? 0);
        $branchName = $request['branch_name'] ?? ($request['branch'] ?? null);

        switch ($stageStatus) {
            // 1. Director Approved — final approver depends on the
            //    requestor's branch.
            case 'DIRECTOR_APPROVED':
                return [$this->buildOfficer($this->directorApprovedRole($branchName), $branchId)];

            // 2 & 3. Quote Review / Quote Selected — the requestor and the
            //    applicable branch head are jointly responsible.
            case 'QUOTE_REVIEW_PENDING':
            case 'QUOTE_APPROVED':
                return $this->requestorAndBranchHeadOfficers($request);

            // 4 & 5 & 8. Funds Verified / Commitment Form / Invoice — Finance
            //    Officer for the requestor's branch.
            case 'FUNDS_VERIFIED':
            case 'COMMITMENTS_PENDING':
            case 'INVOICE_RECEIVED':
                return [$this->buildOfficer('Finance Officer', $branchId)];

            // 6 & 7. Purchase Order / RFQ Letters — Procurement Officer and
            //    Director Procurement (organisation-wide roles).
            case 'PO_PENDING':
            case 'RFQ_LETTER_AVAILABLE':
                return [
                    $this->buildOfficer('Procurement Officer', 0),
                    $this->buildOfficer('Director Procurement', 0),
                ];

            // 9. HOD Approved — the Government Chemist, or the branch's
            //    Government-Chemist-designated HOD, depending on the
            //    approved branch mapping.
            case 'HOD_APPROVED':
                return $this->hodApprovedOfficers($branchId, $branchName);

            default:
                return null;
        }
    }

    /**
     * Requirement 1 — "Director Approved" responsible officer by branch:
     *   - Analytical and Advisory Branch → Deputy Government Chemist
     *   - HRM&A Branch                  → Director of HRM&A
     *   - Executive Branch              → HOD
     *   - Any other branch (fallback)   → Director of HRM&A
     */
    private function directorApprovedRole(?string $branchName): string
    {
        switch (self::classifyBranch($branchName)) {
            case 'ANALYTICAL_ADVISORY':
                return 'Deputy Government Chemist';
            case 'HRMA':
                return 'Director HRM&A';
            case 'EXECUTIVE':
                return 'HOD';
            default:
                return 'Director HRM&A';
        }
    }

    /**
     * Requirement 9 — "HOD Approved" responsible officer by branch mapping.
     * The Analytical and Advisory Branch's approval chain bypasses a
     * separate HOD entirely and escalates straight to the Government
     * Chemist line; every other branch is represented by its own
     * Government-Chemist-designated HOD.
     */
    private function hodApprovedOfficers(int $branchId, ?string $branchName): array
    {
        if (self::classifyBranch($branchName) === 'ANALYTICAL_ADVISORY') {
            return [$this->buildOfficer('Government Chemist', 0)];
        }

        return [$this->buildOfficer('HOD', $branchId)];
    }

    /**
     * Requirements 2 & 3 — Quote Review / Quote Selected: the requestor
     * (the request creator) and the applicable branch head are both
     * responsible.
     */
    private function requestorAndBranchHeadOfficers(array $request): array
    {
        $officers = [];

        $requestorId = (int) ($request['created_by'] ?? 0);
        $officers[]  = [
            'role' => 'Requestor',
            'name' => $requestorId > 0 ? $this->findUserFullName($requestorId) : null,
        ];

        $branchId   = (int) ($request['branch_id'] ?? 0);
        $officers[] = $this->buildOfficer('Branch Head', $branchId);

        return $officers;
    }

    /**
     * Resolve a single officer entry for a role, scoping to branch where
     * applicable. Returns a role/name pair; name is null when nobody can be
     * uniquely identified (role not configured, no active holder, or
     * ambiguous — more than one active holder).
     */
    private function buildOfficer(string $role, int $branchId): array
    {
        return ['role' => $role, 'name' => $this->findRoleUser($role, $branchId, '')];
    }

    /**
     * Remove duplicate officers from a responsibility list. Two entries are
     * considered duplicates when they resolve to the same named person —
     * their roles are merged into a single "Role A / Role B" label. Entries
     * with no resolved name are kept once per distinct role.
     *
     * @param array<int, array{role: string, name: string|null}> $officers
     * @return array<int, array{role: string, name: string|null}>
     */
    private static function dedupeOfficers(array $officers): array
    {
        $byName    = [];
        $byNoName  = [];

        foreach ($officers as $officer) {
            $role = (string) ($officer['role'] ?? '');
            $name = $officer['name'] ?? null;

            if ($name !== null && $name !== '') {
                if (!isset($byName[$name])) {
                    $byName[$name] = ['name' => $name, 'roles' => []];
                }
                if (!in_array($role, $byName[$name]['roles'], true)) {
                    $byName[$name]['roles'][] = $role;
                }
            } else {
                // Keep only one entry per distinct unresolved role.
                $byNoName[$role] = ['role' => $role, 'name' => null];
            }
        }

        $result = [];
        foreach ($byName as $entry) {
            $result[] = ['role' => implode(' / ', $entry['roles']), 'name' => $entry['name']];
        }
        foreach ($byNoName as $entry) {
            $result[] = $entry;
        }

        return $result;
    }

    /**
     * Normalize a branch name for comparison: trims, collapses whitespace,
     * upper-cases, and canonicalises known "HRM&A" spelling variants
     * (HRM&A, HRM & A, HRMA) to a single token so comparisons are resilient
     * to punctuation/spacing differences.
     */
    private static function normalizeBranchName(?string $branchName): string
    {
        $normalized = strtoupper(trim((string) $branchName));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\bHRM\s*&?\s*A\b/', 'HRMA', $normalized) ?? $normalized;
        $normalized = str_replace('&', ' AND ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * Classify a branch name into one of the branch categories used by the
     * responsible-officer business rules.
     *
     * @return 'ANALYTICAL_ADVISORY'|'HRMA'|'EXECUTIVE'|'OTHER'
     */
    private static function classifyBranch(?string $branchName): string
    {
        $normalized = self::normalizeBranchName($branchName);

        if ($normalized === '') {
            return 'OTHER';
        }
        if (str_contains($normalized, 'HRMA')) {
            return 'HRMA';
        }
        if (str_contains($normalized, 'ANALYTICAL') && str_contains($normalized, 'ADVISORY')) {
            return 'ANALYTICAL_ADVISORY';
        }
        if (str_contains($normalized, 'EXECUTIVE')) {
            return 'EXECUTIVE';
        }

        return 'OTHER';
    }

    /**
     * Look up a user's full name by ID. Returns null when the user does
     * not exist or is inactive so callers can render a clear
     * "not yet assigned" fallback rather than a misleading blank value.
     */
    private function findUserFullName(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT full_name FROM users WHERE user_id = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$userId]);
        $name = $stmt->fetchColumn();

        return ($name !== false) ? (string) $name : null;
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
                    'action' => 'Approve this request as the branch\'s HOD (or Government Chemist for the Analytical and Advisory Branch).',
                ],
                'FUNDS_VERIFIED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Verify that sufficient funds are available for this request.',
                ],
                'DIRECTOR_APPROVED' => [
                    'role'   => 'Deputy Government Chemist',
                    'action' => 'Review and provide final approval for this request.',
                ],
                'GC_APPROVED' => [
                    'role'   => 'Procurement Officer',
                    'action' => 'Generate and issue the RFQ letter to shortlisted vendors.',
                ],
                'RFQ_LETTER_AVAILABLE' => [
                    'role'   => 'Procurement Officer / Director Procurement',
                    'action' => 'Collect and record vendor quotations for this request.',
                ],
                'QUOTE_REVIEW_PENDING' => [
                    'role'   => 'Requestor / Branch Head',
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
                    'role'   => 'Requestor / Branch Head',
                    'action' => 'Confirm the selected quotation before it proceeds to Finance.',
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
                    'role'   => 'Accounts Officer',
                    'action' => 'Upload and record the vendor invoice once goods are received.',
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
                    'role'   => 'Finance Officer',
                    'action' => 'Verify that funds are available for this service contract.',
                ],
                'DIRECTOR_APPROVED' => [
                    'role'   => 'Deputy Government Chemist',
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
     * @param string $role            Job title / role name from the static map
     * @param int    $branchId        Branch to scope the search
     * @param string $currentUserRole Viewer role (reserved for future stricter gates)
     */
    private function findRoleUser(string $role, int $branchId, string $currentUserRole): ?string
    {
        // Scope search: roles that are branch-specific should match on branch_id
        $branchScopedRoles = ['HOD', 'Head of Department', 'Branch Head', 'Finance Officer'];
        $isBranchScoped     = in_array($role, $branchScopedRoles, true);

        // Branch-scoped roles cannot be resolved without a valid branch.
        // Organisation-wide roles (Government Chemist, Deputy Government
        // Chemist, Director HRM&A, Procurement Officer, Director
        // Procurement, etc.) are looked up regardless of branch.
        if ($isBranchScoped && $branchId <= 0) {
            return null;
        }

        if ($isBranchScoped) {
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
}
