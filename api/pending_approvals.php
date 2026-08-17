<?php
/**
 * API Endpoint: Pending Approvals for HOD/Branch Head
 * Returns pending petty cash and reimbursement requests awaiting approval
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/workflow.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/auth.php';

// Set content type early (before any early exits)
header('Content-Type: application/json');

// Verify authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_name'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = (int)$_SESSION['user_id'];

// Check if user is HOD or Branch Head
$approverRole = getCurrentApproverRole();
if (!$approverRole) {
    http_response_code(403);
    echo json_encode(['error' => 'User is not a HOD or Branch Head']);
    exit;
}

try {
    $requestType = $_GET['type'] ?? 'both'; // 'petty_cash', 'reimbursement', or 'both'
    
    $response = [
        'petty_cash' => [],
        'reimbursement' => [],
        'summary' => [
            'petty_cash_count' => 0,
            'petty_cash_total' => 0.00,
            'reimbursement_count' => 0,
            'reimbursement_total' => 0.00,
        ]
    ];

    // Fetch petty cash approvals if requested
    if (in_array($requestType, ['petty_cash', 'both'])) {
        $response['petty_cash'] = getPendingPettyCashApprovals($pdo, $userId, $approverRole);
        
        $totalPc = 0.00;
        foreach ($response['petty_cash'] as $req) {
            $totalPc += (float)$req['estimated_value'];
        }
        $response['summary']['petty_cash_count'] = count($response['petty_cash']);
        $response['summary']['petty_cash_total'] = $totalPc;
    }

    // Fetch reimbursement approvals if requested
    if (in_array($requestType, ['reimbursement', 'both'])) {
        $response['reimbursement'] = getPendingReimbursementApprovals($pdo, $userId, $approverRole);
        
        $totalReimb = 0.00;
        foreach ($response['reimbursement'] as $req) {
            $totalReimb += (float)$req['estimated_value'];
        }
        $response['summary']['reimbursement_count'] = count($response['reimbursement']);
        $response['summary']['reimbursement_total'] = $totalReimb;
    }

    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
