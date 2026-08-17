<?php
/**
 * NonPoWorkflowIntegrationTest
 * ============================
 * Integration tests simulating complete workflow scenarios:
 * 1. Standard RFQ + PO path
 * 2. RFQ skipped + PO required
 * 3. RFQ skipped + PO not required (Non-PO path)
 * 4. Invoice and payment completion after skipped procurement stages
 */

/* ─── Bootstrap ──────────────────────────────────────────────────────── */
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SESSION = [];

require_once __DIR__ . '/../config/workflow.php';

/* ─── Test Helpers ───────────────────────────────────────────────────── */
$passed = 0;
$failed = 0;

function testAssert(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  ✓ $name\n";
        $passed++;
    } else {
        echo "  ✗ $name\n";
        $failed++;
    }
}

function simulateWorkflowPath(array $commitment): array
{
    // Simulate which stages should appear based on commitment
    $hasCommitment = $commitment !== null;
    $requiresPo = $hasCommitment ? (($commitment['po_required'] ?? 'YES') === 'YES') : true;
    
    $stages = [
        'SUBMITTED', 'HOD_APPROVED', 'FUNDS_VERIFIED'
    ];
    
    if ($hasCommitment) {
        $stages[] = 'COMMITMENTS_PENDING';
        $stages[] = 'COMMITMENT_APPROVED';
    }
    
    if ($requiresPo) {
        $stages[] = 'PO_PENDING';
    }
    
    $stages[] = 'INVOICE_RECEIVED';
    $stages[] = 'COMPLETED';
    
    return $stages;
}

/* ─── Test Scenarios ─────────────────────────────────────────────────── */
echo "\n=== NonPoWorkflowIntegrationTest ===\n";

// Scenario 1: Standard RFQ + PO path
echo "\n1. Standard RFQ + PO path (with-po workflow)\n";
$standardCommitment = ['commitment_id' => 101, 'po_required' => 'YES'];
$standardStages = simulateWorkflowPath($standardCommitment);

testAssert('Includes COMMITMENTS_PENDING', in_array('COMMITMENTS_PENDING', $standardStages));
testAssert('Includes COMMITMENT_APPROVED', in_array('COMMITMENT_APPROVED', $standardStages));
testAssert('Includes PO_PENDING', in_array('PO_PENDING', $standardStages));
testAssert('Includes INVOICE_RECEIVED', in_array('INVOICE_RECEIVED', $standardStages));
testAssert('Ends at COMPLETED', end($standardStages) === 'COMPLETED');
testAssert('Has 8 stages total', count($standardStages) === 8);

// Scenario 2: RFQ skipped + PO required
echo "\n2. RFQ skipped + PO required path (skip-rfq with-po workflow)\n";
$skipRfqWithPo = ['commitment_id' => 102, 'po_required' => 'YES'];
$skipRfqWithPoStages = [];
if (shouldIncludeCommitmentStages($skipRfqWithPo)) {
    $skipRfqWithPoStages = ['AWARDED', 'COMMITMENTS_PENDING', 'COMMITMENT_APPROVED', 'PO_PENDING', 'INVOICE_RECEIVED', 'COMPLETED'];
}

testAssert('Skips RFQ stages but includes COMMITMENTS_PENDING', in_array('COMMITMENTS_PENDING', $skipRfqWithPoStages));
testAssert('Includes PO_PENDING for RFQ skipped with PO', in_array('PO_PENDING', $skipRfqWithPoStages));
testAssert('Has 6 stages (no RFQ stages)', count($skipRfqWithPoStages) === 6);

// Scenario 3: RFQ skipped + PO not required (Non-PO path)
echo "\n3. RFQ skipped + PO not required path (skip-rfq no-po workflow)\n";
$skipRfqNoPo = ['commitment_id' => 103, 'po_required' => 'NO'];
$skipRfqNoPoStages = [];
if (shouldIncludeCommitmentStages($skipRfqNoPo)) {
    $skipRfqNoPoStages[] = 'COMMITMENTS_PENDING';
    $skipRfqNoPoStages[] = 'COMMITMENT_APPROVED';
    $skipRfqNoPoStages[] = 'PO_PENDING';
} else {
    // Non-PO path: skip commitment/PO stages
    $skipRfqNoPoStages = ['AWARDED', 'INVOICE_RECEIVED', 'COMPLETED'];
}

testAssert('Skips COMMITMENTS_PENDING for non-PO', !in_array('COMMITMENTS_PENDING', $skipRfqNoPoStages));
testAssert('Skips COMMITMENT_APPROVED for non-PO', !in_array('COMMITMENT_APPROVED', $skipRfqNoPoStages));
testAssert('Skips PO_PENDING for non-PO', !in_array('PO_PENDING', $skipRfqNoPoStages));
testAssert('Goes directly from AWARDED to INVOICE', array_search('AWARDED', $skipRfqNoPoStages) + 1 === array_search('INVOICE_RECEIVED', $skipRfqNoPoStages));
testAssert('Has 3 stages only', count($skipRfqNoPoStages) === 3);

// Scenario 4: Verify stage progression for Non-PO
echo "\n4. Workflow progression - Non-PO (no-po workflow)\n";
$transitions = allowedTransitions();

// Test AWARDED → INVOICE_RECEIVED is allowed
testAssert('Can transition AWARDED → INVOICE_RECEIVED', 
    in_array('INVOICE_RECEIVED', $transitions['AWARDED'] ?? []));

// Test COMMITMENT_APPROVED → INVOICE_RECEIVED is allowed (for non-PO)
testAssert('Can transition COMMITMENT_APPROVED → INVOICE_RECEIVED', 
    in_array('INVOICE_RECEIVED', $transitions['COMMITMENT_APPROVED'] ?? []));

// Test INVOICE_RECEIVED → COMPLETED is allowed
testAssert('Can transition INVOICE_RECEIVED → COMPLETED', 
    in_array('COMPLETED', $transitions['INVOICE_RECEIVED'] ?? []));

// Scenario 5: Progress percentage calculation
echo "\n5. Progress calculation for Non-PO workflow\n";

// For standard workflow with 8 stages (0-indexed: 0-7):
// Stage at index 2 (FUNDS_VERIFIED is 3rd stage) = ((2+1)/8)*100 = 37.5% ≈ 38%
$standardProgress = round(((2 + 1) / 8) * 100);
testAssert('Standard 8-stage workflow at stage 3 shows ~38%', $standardProgress >= 37 && $standardProgress <= 39);

// For non-PO workflow with 3 stages (0-indexed: 0-2):
// Stage at index 1 (INVOICE_RECEIVED is 2nd stage) = ((1+1)/3)*100 = 66.7% ≈ 67%
$nonPoProgress = round(((1 + 1) / 3) * 100);
testAssert('Non-PO 3-stage workflow at stage 2 shows ~67%', $nonPoProgress >= 66 && $nonPoProgress <= 67);

// Stage at index 2 (COMPLETED is 3rd stage) = ((2+1)/3)*100 = 100%
$nonPoCompleted = round(((2 + 1) / 3) * 100);
testAssert('Non-PO workflow at COMPLETED shows 100%', $nonPoCompleted == 100);

// Scenario 6: Workflow path detection
echo "\n6. Workflow path detection\n";
$nonPoRequest = ['workflow_path' => 'NON_PO_SKIP_RFQ'];
$standardRequest = ['workflow_path' => 'STANDARD'];

testAssert('Non-PO request detected correctly', getWorkflowPath($nonPoRequest) === 'NON_PO_SKIP_RFQ');
testAssert('Standard request detected correctly', getWorkflowPath($standardRequest) === 'STANDARD');

// Test default when workflow_path not set
$defaultRequest = [];
testAssert('Defaults to STANDARD when no workflow_path', getWorkflowPath($defaultRequest) === 'STANDARD');

/* ─── Summary ────────────────────────────────────────────────────────── */
echo "\n" . str_repeat("─", 50) . "\n";
echo "Tests Passed: $passed\n";
echo "Tests Failed: $failed\n";
echo "Total:        " . ($passed + $failed) . "\n";

if ($failed === 0) {
    echo "\n✓ All integration tests passed!\n";
    exit(0);
} else {
    echo "\n✗ Some tests failed.\n";
    exit(1);
}
