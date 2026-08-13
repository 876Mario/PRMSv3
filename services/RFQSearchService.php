<?php
/**
 * RFQSearchService
 * ================
 * Server-side search functionality for RFQ list.
 * Handles search term parsing, query building, authorization, and performance optimization.
 */

class RFQSearchService
{
    private $pdo;
    private $userId;
    private $userRole;

    public function __construct(PDO $pdo, int $userId, string $userRole)
    {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->userRole = $userRole;
    }

    /**
     * Search RFQs based on search term and filters
     * Returns array: ['rfqs' => [], 'total_count' => int, 'search_term' => string]
     */
    public function search(string $searchTerm, int $perPage, int $offset): array
    {
        if (empty(trim($searchTerm))) {
            return ['rfqs' => [], 'total_count' => 0, 'search_term' => ''];
        }

        $searchTerm = trim($searchTerm);
        
        // Build search query with authorization
        $whereClause = $this->buildSearchWhereClause();
        $countQuery = $this->buildCountQuery($whereClause);
        $searchQuery = $this->buildSearchQuery($whereClause);

        $params = $this->getAuthParams();
        $searchParams = $this->buildSearchParams($searchTerm);
        $params = array_merge($params, $searchParams);

        // Get total count
        $countStmt = $this->pdo->prepare($countQuery);
        $countStmt->execute($params);
        $totalCount = (int)$countStmt->fetchColumn();

        // Add pagination
        $params[':limit'] = $perPage;
        $params[':offset'] = $offset;

        // Get results
        $searchStmt = $this->pdo->prepare($searchQuery);
        $searchStmt->execute($params);
        $rfqs = $searchStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get vendor counts for each RFQ
        foreach ($rfqs as &$rfq) {
            $vendorStmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM rfq_vendors WHERE rfq_id = ?"
            );
            $vendorStmt->execute([$rfq['rfq_id']]);
            $rfq['vendor_count'] = (int)$vendorStmt->fetchColumn();
        }
        unset($rfq);

        return [
            'rfqs' => $rfqs,
            'total_count' => $totalCount,
            'search_term' => $searchTerm
        ];
    }

    /**
     * Build the WHERE clause that applies authorization rules
     */
    private function buildSearchWhereClause(): string
    {
        $where = [];

        // Apply branch filtering for Director HRM&A and Deputy GC
        if ($this->userRole === 'Director HRM&A') {
            $where[] = "pr.branch_id = 5";
        } elseif ($this->userRole === 'Deputy Government Chemist') {
            $where[] = "pr.branch_id = 6";
        }

        // Requestors see only their own requests
        if ($this->userRole === 'Requestor') {
            $where[] = "pr.created_by = :user_id";
        }

        return count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";
    }

    /**
     * Get authorization parameters
     */
    private function getAuthParams(): array
    {
        $params = [];
        if ($this->userRole === 'Requestor') {
            $params[':user_id'] = $this->userId;
        }
        return $params;
    }

    /**
     * Build search WHERE clause for searchable fields
     */
    private function buildSearchParams(string $searchTerm): array
    {
        return [
            ':search_term' => '%' . $this->escapeSearchTerm($searchTerm) . '%'
        ];
    }

    /**
     * Escape search term to prevent SQL injection and handle special characters
     */
    private function escapeSearchTerm(string $term): string
    {
        // Remove leading/trailing whitespace
        $term = trim($term);
        
        // Escape SQL LIKE special characters
        $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        
        return $term;
    }

    /**
     * Build count query for total results
     */
    private function buildCountQuery(string $authWhereClause): string
    {
        $whereClause = $authWhereClause ? $authWhereClause . " AND " : "WHERE ";
        
        return "
            SELECT COUNT(DISTINCT r.rfq_id)
            FROM rfqs r
            JOIN procurement_requests pr ON r.request_id = pr.request_id
            {$whereClause}(
                r.rfq_number LIKE :search_term ESCAPE '\\'
                OR pr.request_number LIKE :search_term ESCAPE '\\'
                OR pr.description LIKE :search_term ESCAPE '\\'
                OR r.status LIKE :search_term ESCAPE '\\'
                OR EXISTS (
                    SELECT 1
                    FROM rfq_vendors rv
                    WHERE rv.rfq_id = r.rfq_id
                    AND (rv.vendor_name LIKE :search_term ESCAPE '\\')
                )
                OR EXISTS (
                    SELECT 1
                    FROM users u
                    WHERE u.user_id = pr.created_by
                    AND u.full_name LIKE :search_term ESCAPE '\\'
                )
                OR EXISTS (
                    SELECT 1
                    FROM branches b
                    WHERE b.branch_id = pr.branch_id
                    AND b.branch_name LIKE :search_term ESCAPE '\\'
                )
            )
        ";
    }

    /**
     * Build main search query with pagination
     */
    private function buildSearchQuery(string $authWhereClause): string
    {
        $whereClause = $authWhereClause ? $authWhereClause . " AND " : "WHERE ";
        
        return "
            SELECT DISTINCT r.rfq_id, r.rfq_number, r.status, r.created_at,
                   pr.request_number, pr.request_id, pr.created_by
            FROM rfqs r
            JOIN procurement_requests pr ON r.request_id = pr.request_id
            {$whereClause}(
                r.rfq_number LIKE :search_term ESCAPE '\\'
                OR pr.request_number LIKE :search_term ESCAPE '\\'
                OR pr.description LIKE :search_term ESCAPE '\\'
                OR r.status LIKE :search_term ESCAPE '\\'
                OR EXISTS (
                    SELECT 1
                    FROM rfq_vendors rv
                    WHERE rv.rfq_id = r.rfq_id
                    AND (rv.vendor_name LIKE :search_term ESCAPE '\\')
                )
                OR EXISTS (
                    SELECT 1
                    FROM users u
                    WHERE u.user_id = pr.created_by
                    AND u.full_name LIKE :search_term ESCAPE '\\'
                )
                OR EXISTS (
                    SELECT 1
                    FROM branches b
                    WHERE b.branch_id = pr.branch_id
                    AND b.branch_name LIKE :search_term ESCAPE '\\'
                )
            )
            ORDER BY r.created_at DESC
            LIMIT :limit OFFSET :offset
        ";
    }

    /**
     * Get list of searchable field descriptions for UI help text
     */
    public static function getSearchableFields(): array
    {
        return [
            'RFQ Number' => 'Unique identifier for the request for quotation',
            'Request Number' => 'Procurement request identifier',
            'Description' => 'Request description text',
            'Vendor Name' => 'Name of vendor submitting quote',
            'Status' => 'RFQ status (OPEN, EVALUATION, AWARDED, CLOSED, etc.)',
            'Requester Name' => 'Name of person who created the request',
            'Department/Branch' => 'Branch or department name',
        ];
    }
}
?>
