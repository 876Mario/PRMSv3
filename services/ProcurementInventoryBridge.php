<?php
/**
 * services/ProcurementInventoryBridge.php
 *
 * Service layer that connects the Procurement module (purchase_orders,
 * procurement_requests, po_items) with the Inventory module
 * (inv_goods_received, inv_grn_items, inv_items, inv_stock,
 *  inv_procurement_escalations).
 *
 * Key capabilities:
 *  - Pre-fill a GRN from a purchase order  (createGrnFromPo)
 *  - Look up current stock level for a description keyword (getStockForDescription)
 *  - Escalate an inventory requisition to a procurement request (escalateRequisition)
 *  - Get GRNs linked to a PO  (getGrnsForPo)
 *  - Get the procurement request linked to an inventory escalation (getProcurementForEscalation)
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/InventoryService.php';

class ProcurementInventoryBridge
{
    /* ================================================================
       GRN PRE-FILL FROM PO
    ================================================================ */

    /**
     * Return PO header + line items suitable for pre-filling the GRN form.
     *
     * @param PDO $pdo
     * @param int $poId
     * @return array|null  ['po' => [...], 'items' => [...]]
     */
    public static function getPOForGrn(PDO $pdo, int $poId): ?array
    {
        $stmt = $pdo->prepare("
            SELECT po.po_id, po.po_number, po.po_total,
                   po.status, po.created_at,
                   v.vendor_id, v.vendor_name
            FROM purchase_orders po
            LEFT JOIN commitments c ON po.commitment_id = c.commitment_id
            LEFT JOIN rfq_quotes rq ON c.selected_quote_id = rq.quote_id
            LEFT JOIN rfq_vendors rv ON rq.rfq_vendor_id = rv.rfq_vendor_id
            LEFT JOIN vendors v ON rv.vendor_id = v.vendor_id
            WHERE po.po_id = ?
        ");
        $stmt->execute([$poId]);
        $po = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$po) return null;

        $stmt = $pdo->prepare("
            SELECT poi.po_item_id, poi.description, poi.qty, poi.unit_price,
                   (poi.qty * poi.unit_price) AS line_total,
                   im.item_id AS matched_item_id,
                   im.item_name AS matched_item_name
            FROM po_items poi
            LEFT JOIN inv_items im ON im.item_status = 'ACTIVE'
                AND LOWER(im.item_name) LIKE LOWER(CONCAT('%', SUBSTRING_INDEX(poi.description, ' ', 3), '%'))
            WHERE poi.po_id = ?
            GROUP BY poi.po_item_id
            ORDER BY poi.po_item_id
        ");
        $stmt->execute([$poId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return ['po' => $po, 'items' => $items];
    }

    /**
     * Create a DRAFT GRN pre-populated with PO data.
     * Returns the new grn_id, or throws on failure.
     *
     * @param PDO   $pdo
     * @param int   $poId
     * @param int   $receivingLocationId
     * @param int   $receivedByUserId
     * @return int  grn_id
     */
    public static function createGrnFromPo(
        PDO $pdo,
        int $poId,
        int $receivingLocationId,
        int $receivedByUserId
    ): int {
        $data = self::getPOForGrn($pdo, $poId);
        if (!$data) throw new RuntimeException("Purchase order #$poId not found.");

        $po    = $data['po'];
        $items = $data['items'];

        if (empty($items)) {
            throw new RuntimeException("Purchase order #$poId has no line items.");
        }

        $grnNumber = InventoryService::generateDocNumber($pdo, 'GRN', 'inv_goods_received', 'grn_number');

        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                INSERT INTO inv_goods_received
                    (grn_number, po_reference, procurement_po_id, supplier_name,
                     received_date, received_by, receiving_location_id,
                     notes, status, created_at)
                VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, 'DRAFT', NOW())
            ")->execute([
                $grnNumber,
                $po['po_number'],
                $poId,
                $po['vendor_name'] ?? '',
                $receivedByUserId,
                $receivingLocationId,
                "Auto-created from PO {$po['po_number']}",
            ]);
            $grnId = (int) $pdo->lastInsertId();

            $insertItem = $pdo->prepare("
                INSERT INTO inv_grn_items
                    (grn_id, item_id, quantity_expected, quantity_received,
                     quantity_accepted, quantity_rejected, unit_cost, condition_on_receipt)
                VALUES (?, ?, ?, 0, 0, 0, ?, 'GOOD')
            ");

            foreach ($items as $line) {
                $itemId = $line['matched_item_id'] ? (int) $line['matched_item_id'] : null;
                if (!$itemId) continue; // Skip lines without a matched inventory item

                $insertItem->execute([
                    $grnId,
                    $itemId,
                    (float) $line['qty'],
                    (float) $line['unit_price'],
                ]);
            }

            logInventoryAudit($pdo, 'inv_goods_received', $grnId, 'CREATED',
                "Draft GRN auto-created from PO {$po['po_number']}");

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $grnId;
    }

    /* ================================================================
       STOCK LEVEL LOOKUP (used in procurement request form)
    ================================================================ */

    /**
     * Return current stock summary for items whose name matches $keyword.
     * Used on the procurement add form to show "we already have X in stock".
     *
     * @param PDO    $pdo
     * @param string $keyword  Free-text search term
     * @return array
     */
    public static function getStockForDescription(PDO $pdo, string $keyword): array
    {
        if (strlen(trim($keyword)) < 2) return [];

        $like = '%' . trim($keyword) . '%';
        $stmt = $pdo->prepare("
            SELECT i.item_id, i.item_code, i.item_name, i.uom_id,
                   u.uom_code,
                   COALESCE(SUM(s.quantity_on_hand), 0) AS qty_on_hand,
                   i.reorder_level
            FROM inv_items i
            LEFT JOIN inv_stock s    ON s.item_id = i.item_id AND s.stock_status = 'USABLE'
            LEFT JOIN inv_units_of_measure u ON u.uom_id = i.uom_id
            WHERE i.item_status = 'ACTIVE'
              AND (i.item_name LIKE ? OR i.item_code LIKE ?)
            GROUP BY i.item_id
            ORDER BY qty_on_hand DESC
            LIMIT 10
        ");
        $stmt->execute([$like, $like]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ================================================================
       GRNs LINKED TO A PO
    ================================================================ */

    /**
     * Return all GRNs that were created from the given PO.
     *
     * @param PDO $pdo
     * @param int $poId
     * @return array
     */
    public static function getGrnsForPo(PDO $pdo, int $poId): array
    {
        $stmt = $pdo->prepare("
            SELECT g.grn_id, g.grn_number, g.status,
                   g.received_date, g.supplier_name,
                   u.full_name AS received_by_name,
                   l.location_code, l.site_name
            FROM inv_goods_received g
            LEFT JOIN users u         ON g.received_by = u.user_id
            LEFT JOIN inv_locations l ON g.receiving_location_id = l.location_id
            WHERE g.procurement_po_id = ?
            ORDER BY g.received_date DESC, g.grn_id DESC
        ");
        $stmt->execute([$poId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ================================================================
       INVENTORY REQUISITION ESCALATION TO PROCUREMENT
    ================================================================ */

    /**
     * Escalate an unfulfillable inventory requisition to a procurement request.
     * Creates a DRAFT procurement_request and links it via the escalation table.
     *
     * @param PDO    $pdo
     * @param int    $invRequisitionId
     * @param int    $requestingBranchId
     * @param int    $createdByUserId
     * @param string $notes
     * @return int   escalation_id
     */
    public static function escalateRequisition(
        PDO    $pdo,
        int    $invRequisitionId,
        int    $requestingBranchId,
        int    $createdByUserId,
        string $notes = ''
    ): int {
        // Load requisition
        $req = $pdo->prepare("
            SELECT r.*, u.full_name AS requester_name
            FROM inv_requisitions r
            LEFT JOIN users u ON r.requested_by = u.user_id
            WHERE r.requisition_id = ?
        ");
        $req->execute([$invRequisitionId]);
        $requisition = $req->fetch(PDO::FETCH_ASSOC);
        if (!$requisition) throw new RuntimeException("Inventory requisition #$invRequisitionId not found.");

        // Load requisition items
        $itemStmt = $pdo->prepare("
            SELECT ri.*, i.item_name, i.item_code, i.average_cost
            FROM inv_requisition_items ri
            JOIN inv_items i ON ri.item_id = i.item_id
            WHERE ri.requisition_id = ?
        ");
        $itemStmt->execute([$invRequisitionId]);
        $reqItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        // Build procurement request description
        $itemList = implode(', ', array_map(
            fn($it) => "{$it['item_name']} (qty: {$it['quantity_requested']})",
            $reqItems
        ));
        $description = "Escalated from inventory requisition {$requisition['requisition_number']}. "
            . "Items: $itemList. "
            . ($notes ? "Notes: $notes" : '');

        $estimatedValue = array_sum(array_map(
            fn($it) => (float) $it['quantity_requested'] * (float) $it['average_cost'],
            $reqItems
        ));

        $pdo->beginTransaction();
        try {
            $prNum = generateRequestNumber($pdo);

            // Create procurement request (DRAFT)
            $pdo->prepare("
                INSERT INTO procurement_requests
                    (branch_id, request_number, request_date, description,
                     request_type, status, estimated_value, currency, created_by, created_at)
                VALUES (?, ?, CURDATE(), ?, 'REGULAR', 'DRAFT', ?, 'JMD', ?, NOW())
            ")->execute([
                $requestingBranchId,
                $prNum,
                $description,
                $estimatedValue,
                $createdByUserId,
            ]);
            $procRequestId = (int) $pdo->lastInsertId();

            // Record escalation link
            $pdo->prepare("
                INSERT INTO inv_procurement_escalations
                    (inv_requisition_id, procurement_request_id, escalated_by, escalation_notes, status)
                VALUES (?, ?, ?, ?, 'LINKED')
            ")->execute([$invRequisitionId, $procRequestId, $createdByUserId, $notes]);
            $escalationId = (int) $pdo->lastInsertId();

            // Mark requisition as escalated
            $pdo->prepare("
                UPDATE inv_requisitions SET status = 'ESCALATED_TO_PROCUREMENT' WHERE requisition_id = ?
            ")->execute([$invRequisitionId]);

            logInventoryAudit($pdo, 'inv_requisitions', $invRequisitionId, 'ESCALATED',
                "Escalated to procurement request $prNum");

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $escalationId;
    }

    /**
     * Return the procurement request linked to an escalated inventory requisition.
     *
     * @param PDO $pdo
     * @param int $invRequisitionId
     * @return array|null
     */
    public static function getProcurementForEscalation(PDO $pdo, int $invRequisitionId): ?array
    {
        $stmt = $pdo->prepare("
            SELECT pr.request_id, pr.request_number, pr.status,
                   pr.created_at, pr.estimated_value,
                   e.escalation_id, e.escalated_at, e.escalation_notes
            FROM inv_procurement_escalations e
            JOIN procurement_requests pr ON e.procurement_request_id = pr.request_id
            WHERE e.inv_requisition_id = ?
              AND e.status = 'LINKED'
            ORDER BY e.escalated_at DESC
            LIMIT 1
        ");
        $stmt->execute([$invRequisitionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
