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
     *                                 Must include request_type, branch_id, and
     *                                 created_by for full dynamic resolution.
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
     *   responsible_roles:   array<int, array{role: string, user: string|null}>,
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
            'responsible_roles'  => [],   // non-empty for multi-officer stages
            'assigned_user'      => null,
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

            // Apply dynamic overrides — these replace / augment the static role
            $this->applyDynamicOverrides($result, $workflowType, $stageStatus, $request, $branchId);
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
        } elseif ($result['is_configured'] && empty($result['responsible_roles'])) {
            // For pending single-officer stages, try to find the unique role-holder
            // in this branch.  Multi-officer stages resolve their own users above.
            $assignedUser = $this->findRoleUser(
                $result['responsible_role'],
                $branchId,
                $currentUserRole
            );
            if ($assignedUser !== null) {
                $result['assigned_user'] = $assignedUser;
                $result['source_type']   = 'Assigned to';
            }
        }

        return $result;
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
                    'action' => 'Review and approve this procurement request.',
                ],
                'FUNDS_VERIFIED' => [
                    'role'   => 'Finance Officer',
                    'action' => 'Verify that sufficient funds are available for this request.',
                ],
                'DIRECTOR_APPROVED' => [
                    // Dynamic: resolved per branch in applyDynamicOverrides()
                    'role'   => 'Director HRM&A',
                    'action' => 'Review and provide directorial approval for this request.',
                ],
                'GC_APPROVED' => [
                    'role'   => 'Procurement Officer',
                    'action' => 'Generate and issue the RFQ letter to shortlisted vendors.',
                ],
                'RFQ_LETTER_AVAILABLE' => [
                    // Dynamic: Procurement Officer + Director Procurement (multi-officer)
                    'role'   => 'Procurement Officer',
                    'action' => 'Generate and issue RFQ letters to shortlisted vendors.',
                ],
                'QUOTE_REVIEW_PENDING' => [
                    // Dynamic: Requestor + Branch Head (multi-officer)
                    'role'   => 'Requestor / HOD',
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
                    // Dynamic: Requestor + Branch Head (multi-officer)
                    'role'   => 'Requestor / HOD',
                    'action' => 'Review and confirm the selected quotation.',
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
                    // Dynamic: Procurement Officer + Director Procurement (multi-officer)
                    'role'   => 'Procurement Officer',
                    'action' => 'Generate and process the purchase order.',
                ],
                'PO_APPROVED' => [
                    // Dynamic: Procurement Officer + Director Procurement (multi-officer)
                    'role'   => 'Procurement Officer',
                    'action' => 'Finalise the approved purchase order.',
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
                    // Dynamic: resolved per branch in applyDynamicOverrides()
                    'role'   => 'HOD',
                    'action' => 'Review and approve this service contract request.',
                ],
                'DIRECTOR_APPROVED' => [
                    // Dynamic: resolved per branch in applyDynamicOverrides()
                    'role'   => 'Director HRM&A',
                    'action' => 'Provide directorial approval for this service contract.',
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
     * Apply branch-specific and multi-officer dynamic overrides to $result.
     *
     * Handles stages whose responsible officers depend on the request branch or
     * require multiple named officers rather than a single static role.
     *
     * Mutates $result in-place.
     */
    private function applyDynamicOverrides(
        array  &$result,
        string $workflowType,
        string $stageStatus,
        array  $request,
        int    $branchId
    ): void {
        // ── DIRECTOR_APPROVED: branch-based responsible officer ────────────────
        if ($stageStatus === 'DIRECTOR_APPROVED'
            && in_array($workflowType, ['REGULAR', 'SERVICE_CONTRACT'], true)) {
            $result['responsible_role'] = $this->resolveDirectorApprovedRole($branchId);
            return;
        }

        // ── HOD_APPROVED: HOD for the request's branch ────────────────────────
        if ($stageStatus === 'HOD_APPROVED'
            && in_array($workflowType, ['REGULAR', 'SERVICE_CONTRACT'], true)) {
            $result['responsible_role'] = 'HOD';
            return;
        }

        if ($workflowType !== 'REGULAR') {
            return;
        }

        // ── Quote Review / Quote Selected: Requestor + HOD ────────────────────
        if (in_array($stageStatus, ['QUOTE_REVIEW_PENDING', 'QUOTE_APPROVED'], true)) {
            $officers = $this->resolveQuoteOfficers($request, $branchId);
            $result['responsible_roles'] = $officers;
            if (!empty($officers)) {
                $result['responsible_role'] = $officers[0]['role'];
            }
            return;
        }

        // ── RFQ Letters: Procurement Officer + Director Procurement ───────────
        if ($stageStatus === 'RFQ_LETTER_AVAILABLE') {
            $result['responsible_roles'] = $this->resolveProcurementOfficers();
            return;
        }

        // ── Purchase Order: Procurement Officer + Director Procurement ────────
        if (in_array($stageStatus, ['PO_PENDING', 'PO_APPROVED'], true)) {
            $result['responsible_roles'] = $this->resolveProcurementOfficers();
            return;
        }
    }

    /**
     * Resolve the responsible officer for the DIRECTOR_APPROVED stage based on
     * the request branch.
     *
     * Branch mapping (normalised):
     *   Analytical & Advisory → Deputy Government Chemist
     *   HRM&A (and variants)  → Director HRM&A
     *   Executive Branch      → HOD
     *   Anything else         → Director HRM&A  (default / fallback)
     */
    private function resolveDirectorApprovedRole(int $branchId): string
    {
        if ($branchId === 0) {
            return 'Director HRM&A';
        }

        $branchName = $this->fetchBranchName($branchId);
        $normalized = $this->normalizeBranchName($branchName);

        // Analytical & Advisory Branch
        if (str_contains($normalized, 'analytical') && str_contains($normalized, 'advisory')) {
            return 'Deputy Government Chemist';
        }

        // HRM&A Branch — matches "hrm&a", "hrma", "hrm & a",
        // "human resource management", and the DB value "HRMA / Administration Branch"
        $stripped = preg_replace('/[^a-z0-9]/', '', $normalized);
        if (str_contains($stripped, 'hrma')
            || str_contains($normalized, 'human resource management')
            || str_contains($normalized, 'human resource')) {
            return 'Director HRM&A';
        }

        // Executive Branch
        if (str_contains($normalized, 'executive')) {
            return 'HOD';
        }

        // Default: Director HRM&A
        return 'Director HRM&A';
    }

    /**
     * Fetch the branch name for a given branch_id.
     * Returns an empty string if the branch cannot be found.
     */
    private function fetchBranchName(int $branchId): string
    {
        if ($branchId === 0) {
            return '';
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT branch_name FROM branches WHERE branch_id = ? LIMIT 1'
            );
            $stmt->execute([$branchId]);
            $name = $stmt->fetchColumn();
            return ($name !== false) ? (string) $name : '';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Normalise a branch name for comparison:
     *  - trim and collapse whitespace
     *  - lowercase
     */
    private function normalizeBranchName(string $name): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    }

    /**
     * Build the multi-officer list for Quote Review / Quote Selected stages.
     *
     * Returns: [
     *   ['role' => 'Requestor', 'user' => string|null],
     *   ['role' => 'HOD',       'user' => string|null],
     * ]
     * Duplicate entries (same user name for both roles) are removed.
     */
    private function resolveQuoteOfficers(array $request, int $branchId): array
    {
        $officers = [];

        // Requestor
        $requestorName = $this->resolveRequestorName($request);
        $officers[] = ['role' => 'Requestor', 'user' => $requestorName];

        // Branch HOD
        $hodName = $this->findRoleUser('HOD', $branchId, '');
        $officers[] = ['role' => 'HOD', 'user' => $hodName];

        return $this->deduplicateOfficers($officers);
    }

    /**
     * Build the multi-officer list for RFQ Letters and Purchase Order stages.
     *
     * Returns: [
     *   ['role' => 'Procurement Officer',  'user' => string|null],
     *   ['role' => 'Director Procurement', 'user' => string|null],
     * ]
     */
    private function resolveProcurementOfficers(): array
    {
        $procUser    = $this->findOrgWideRoleUser('Procurement Officer');
        $dirProcUser = $this->findOrgWideRoleUser('Director Procurement');

        $officers = [
            ['role' => 'Procurement Officer',  'user' => $procUser],
            ['role' => 'Director Procurement', 'user' => $dirProcUser],
        ];

        return $this->deduplicateOfficers($officers);
    }

    /**
     * Resolve the full name of the request's creator from the users table.
     * Returns null when created_by is absent or the user cannot be found.
     */
    private function resolveRequestorName(array $request): ?string
    {
        $createdBy = (int) ($request['created_by'] ?? 0);
        if ($createdBy === 0) {
            return null;
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT u.full_name FROM users u WHERE u.user_id = ? AND u.is_active = 1 LIMIT 1'
            );
            $stmt->execute([$createdBy]);
            $name = $stmt->fetchColumn();
            return ($name !== false) ? (string) $name : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Look up a unique, organisation-wide role-holder (no branch scope).
     * Returns the user's full name only when exactly one active user matches.
     */
    private function findOrgWideRoleUser(string $role): ?string
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT u.full_name
                   FROM users u
                   JOIN roles r ON r.id = u.role_id
                  WHERE r.name = ?
                    AND u.is_active = 1
                  LIMIT 2'
            );
            $stmt->execute([$role]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            return (count($rows) === 1) ? $rows[0] : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Remove duplicate officer entries from a multi-officer list.
     *
     * Deduplication key: the resolved user name (when present) or the role name.
     * The first occurrence is kept; subsequent duplicates are discarded.
     *
     * @param  array<int, array{role: string, user: string|null}> $officers
     * @return array<int, array{role: string, user: string|null}>
     */
    private function deduplicateOfficers(array $officers): array
    {
        $seen   = [];
        $result = [];

        foreach ($officers as $officer) {
            // Key on the resolved name when available, otherwise on the role label.
            $key = ($officer['user'] !== null) ? $officer['user'] : $officer['role'];

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[]   = $officer;
            }
        }

        return $result;
    }

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
        if ($branchId === 0) {
            return null;
        }

        // Scope search: roles that are branch-specific should match on branch_id
        $branchScopedRoles = ['HOD', 'Head of Department', 'Branch Head', 'Finance Officer'];

        if (in_array($role, $branchScopedRoles, true) && $branchId > 0) {
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
