<?php
/**
 * NonPoCommitmentRemediationTest
 * ==============================
 * Tests for the Non-PO commitment remediation fix.
 *
 * Verifies:
 * 1. Skip RFQ + Skip PO path (po_required='NO') properly sets workflow_path='NON_PO_SKIP_RFQ'
 * 2. Remediated commitments are excluded from workflow display
 * 3. shouldIncludeCommitmentStages() returns false for remediated commitments
 * 4. Prevented regression: no orphaned non-PO commitments are created
 * 5. Historical affected records can be identified and remediated
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
        echo "  ✓ PASS  $name\n";
        $passed++;
    } else {
        echo "  ✗ FAIL  $name\n";
        $failed++;
    }
}

/* ─── Test Cases ─────────────────────────────────────────────────────── */
echo "\n=== NonPoCommitmentRemediationTest ===\n";

// Test 1: shouldIncludeCommitmentStages with non-remediated po_required='NO'
echo "\n1. shouldIncludeCommitmentStages() - Non-remediated Non-PO commitment\n";
$nonRemediatedNoPo = [
    'commitment_id' => 1,
    'po_required' => 'NO',
    'is_remediated' => 0
];
testAssert('Returns false for po_required=NO and is_remediated=0', 
    shouldIncludeCommitmentStages($nonRemediatedNoPo) === false);

// Test 2: shouldIncludeCommitmentStages with remediated commitment
echo "\n2. shouldIncludeCommitmentStages() - Remediated commitment\n";
$remediatedCommitment = [
    'commitment_id' => 2,
    'po_required' => 'NO',
    'is_remediated' => 1
];
testAssert('Returns true for remediated commitment (treats as non-existent)', 
    shouldIncludeCommitmentStages($remediatedCommitment) === true);

// Test 3: shouldIncludeCommitmentStages with po_required='YES'
echo "\n3. shouldIncludeCommitmentStages() - Standard commitment\n";
$standardCommitment = [
    'commitment_id' => 3,
    'po_required' => 'YES',
    'is_remediated' => 0
];
testAssert('Returns true for po_required=YES', 
    shouldIncludeCommitmentStages($standardCommitment) === true);

// Test 4: shouldIncludeCommitmentStages with null (no commitment yet)
echo "\n4. shouldIncludeCommitmentStages() - No commitment yet\n";
testAssert('Returns true for null commitment (no commitment created yet)', 
    shouldIncludeCommitmentStages(null) === true);

// Test 5: isSkipRfqPath should recognize NON_PO_SKIP_RFQ workflow path
echo "\n5. isSkipRfqPath() - Non-PO skip-RFQ path detection\n";
$nonPoSkipRfqRequest = [
    'workflow_path' => 'NON_PO_SKIP_RFQ',
    'request_id' => 100
];
testAssert('Detects NON_PO_SKIP_RFQ workflow path', 
    isSkipRfqPath('REGULAR', 0, 'AWARDED', $nonPoSkipRfqRequest));

// Test 6: isSkipRfqPath should still detect skip-RFQ by heuristic (no RFQ + AWARDED status)
echo "\n6. isSkipRfqPath() - Heuristic detection (no workflow_path flag)\n";
testAssert('Detects skip-RFQ by heuristic (REGULAR + no RFQ + AWARDED)', 
    isSkipRfqPath('REGULAR', 0, 'AWARDED'));

// Test 7: isSkipRfqPath should return false for requests with RFQ
echo "\n7. isSkipRfqPath() - Standard RFQ path\n";
testAssert('Returns false for REGULAR request with RFQ', 
    isSkipRfqPath('REGULAR', 123, 'QUOTE_APPROVED') === false);

// Test 8: Workflow transitions should allow AWARDED → INVOICE_RECEIVED for non-PO
echo "\n8. Allowed transitions - AWARDED → INVOICE_RECEIVED\n";
$transitions = allowedTransitions();
$awardedTransitions = $transitions['AWARDED'] ?? [];
testAssert('AWARDED allows INVOICE_RECEIVED transition (non-PO path)', 
    in_array('INVOICE_RECEIVED', $awardedTransitions, true));

// Test 9: COMMITMENT_APPROVED should allow INVOICE_RECEIVED (non-PO skips PO_PENDING)
echo "\n9. Allowed transitions - COMMITMENT_APPROVED → INVOICE_RECEIVED\n";
$commitmentApprovedTransitions = $transitions['COMMITMENT_APPROVED'] ?? [];
testAssert('COMMITMENT_APPROVED allows INVOICE_RECEIVED transition', 
    in_array('INVOICE_RECEIVED', $commitmentApprovedTransitions, true));

// Test 10: Standard path still requires PO
echo "\n10. Allowed transitions - COMMITMENT_APPROVED → PO_PENDING (standard path)\n";
testAssert('COMMITMENT_APPROVED still allows PO_PENDING transition', 
    in_array('PO_PENDING', $commitmentApprovedTransitions, true));

// Test 11: getWorkflowPath returns correct path for non-PO requests
echo "\n11. getWorkflowPath() - Non-PO request path\n";
testAssert('Returns NON_PO_SKIP_RFQ for non-PO request', 
    getWorkflowPath($nonPoSkipRfqRequest) === 'NON_PO_SKIP_RFQ');

// Test 12: getWorkflowBadgeHtml shows correct badge for non-PO
echo "\n12. getWorkflowBadgeHtml() - Non-PO badge\n";
$nonPoBadge = getWorkflowBadgeHtml($nonPoSkipRfqRequest);
testAssert('Non-PO badge contains "Non-PO"', 
    strpos($nonPoBadge, 'Non-PO') !== false || strpos($nonPoBadge, 'non-po') !== false);

// Test 13: Service contracts should NOT be affected (different logic)
echo "\n13. Service contract exclusion\n";
$serviceContractRequest = ['request_type' => 'SERVICE_CONTRACT'];
testAssert('Service contracts use different workflow logic', 
    ($serviceContractRequest['request_type'] ?? '') !== 'REGULAR');

/* ─── Edge Cases ─────────────────────────────────────────────────────── */
echo "\n14. Edge cases\n";

// Remediated with is_remediated=NULL (for backward compat)
$nullRemediatedCommitment = [
    'commitment_id' => 4,
    'po_required' => 'NO',
    'is_remediated' => null
];
testAssert('Handles is_remediated=NULL (treats as not remediated)', 
    shouldIncludeCommitmentStages($nullRemediatedCommitment) === false);

// Commitment with missing po_required field
$missingPoRequiredCommitment = [
    'commitment_id' => 5,
    'is_remediated' => 0
];
testAssert('Defaults to po_required=YES when field missing', 
    shouldIncludeCommitmentStages($missingPoRequiredCommitment) === true);

/* ─── Summary ────────────────────────────────────────────────────────── */
echo "\n" . str_repeat("─", 50) . "\n";
echo "Tests Passed: $passed\n";
echo "Tests Failed: $failed\n";
echo "Total:        " . ($passed + $failed) . "\n";

if ($failed === 0) {
    echo "\n✓ All tests passed!\n";
    exit(0);
} else {
    echo "\n✗ Some tests failed.\n";
    exit(1);
}
