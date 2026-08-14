<?php
/**
 * Data Repair Script: PRE_AUTHORIZED → FUNDS_VERIFIED Migration
 * 
 * This script fixes existing reimbursement requests stuck in the undefined
 * PRE_AUTHORIZED status and migrates them to FUNDS_VERIFIED.
 * 
 * Usage:
 *   php repair_pre_authorized.php --dry-run    # Test mode
 *   php repair_pre_authorized.php              # Actual execution
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/helper.php';

// Check for dry-run mode
$dryRun = in_array('--dry-run', $argv, true);
$mode = $dryRun ? 'DRY-RUN' : 'EXECUTION';

echo "================================================================================\n";
echo "PRE_AUTHORIZED → FUNDS_VERIFIED Migration Script ($mode)\n";
echo "================================================================================\n\n";

try {
    // Find all reimbursement requests in PRE_AUTHORIZED status
    $stmt = $pdo->prepare("
        SELECT 
            request_id,
            request_number,
            created_by,
            created_at,
            status
        FROM procurement_requests
        WHERE request_type = 'REIMBURSEMENT'
        AND status = 'PRE_AUTHORIZED'
        ORDER BY created_at DESC
    ");
    $stmt->execute();
    $affectedRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($affectedRequests) . " reimbursement request(s) in PRE_AUTHORIZED status.\n\n";
    
    if (count($affectedRequests) > 0) {
        echo "Details:\n";
        echo "─────────────────────────────────────────────────────────────────────────────\n";
        
        foreach ($affectedRequests as $req) {
            echo "  • Request ID: " . $req['request_id'] . "\n";
            echo "    Number: " . htmlspecialchars($req['request_number']) . "\n";
            echo "    Created: " . $req['created_at'] . "\n";
            echo "    Current Status: " . $req['status'] . "\n";
            echo "\n";
        }
        
        if (!$dryRun) {
            echo "Proceeding with migration...\n";
            echo "─────────────────────────────────────────────────────────────────────────────\n\n";
            
            $successCount = 0;
            $errorCount = 0;
            
            foreach ($affectedRequests as $req) {
                try {
                    // Update request status
                    $updateStmt = $pdo->prepare("
                        UPDATE procurement_requests
                        SET status = 'FUNDS_VERIFIED',
                            updated_at = NOW()
                        WHERE request_id = ?
                    ");
                    $updateStmt->execute([$req['request_id']]);
                    
                    // Create audit trail entry
                    $auditStmt = $pdo->prepare("
                        INSERT INTO audit_log
                        (entity_type, entity_id, action, user_id, old_value, new_value, change_date, ip_address, user_agent)
                        VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?)
                    ");
                    $auditStmt->execute([
                        'procurement_requests',
                        $req['request_id'],
                        'DATA_MIGRATION',
                        0,  // System user
                        'PRE_AUTHORIZED',
                        'FUNDS_VERIFIED',
                        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                        $_SERVER['HTTP_USER_AGENT'] ?? 'CLI'
                    ]);
                    
                    echo "✓ Request #" . $req['request_id'] . " (" . htmlspecialchars($req['request_number']) . ") migrated successfully\n";
                    $successCount++;
                } catch (PDOException $e) {
                    echo "✗ Request #" . $req['request_id'] . " (" . htmlspecialchars($req['request_number']) . ") failed: " . $e->getMessage() . "\n";
                    $errorCount++;
                }
            }
            
            echo "\n" . "═════════════════════════════════════════════════════════════════════════════\n";
            echo "Migration Summary:\n";
            echo "  Successful: $successCount\n";
            echo "  Failed: $errorCount\n";
            echo "═════════════════════════════════════════════════════════════════════════════\n";
            
        } else {
            echo "DRY-RUN MODE: No changes were made to the database.\n";
            echo "Run without --dry-run flag to apply these changes.\n";
        }
    } else {
        echo "No affected requests found. Migration not needed.\n";
    }
    
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nDone.\n";
?>
