<?php
/**
 * NonPoWorkflowTest
 * =================
 * Tests for Non-PO / Skip RFQ workflow logic.
 *
 * Verifies:
 * - shouldIncludeCommitmentStages() returns false for po_required='NO'
 * - shouldIncludeCommitmentStages() returns true for po_required='YES' or null
 * - Pipeline stages are correctly conditional based on po_required
 * - Allowed transitions support AWARDED → INVOICE_RECEIVED for non-PO paths
 */

/* ─── Bootstrap ──────────────────────────────────────────────────────── */
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SESSION = [];

require_once __DIR__ . '/../config/workflow.php';

/* ─── Helpers ────────────────────────────────────────────────────────── */
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
echo "\n=== NonPoWorkflowTest ===\n";

// Test 1: shouldIncludeCommitmentStages with po_required='NO'
echo "\n1. shouldIncludeCommitmentStages() - Non-PO (po_required='NO')\n";
$commitment_no_po = ['commitment_id' => 1, 'po_required' => 'NO'];
testAssert('Returns false for po_required=NO', shouldIncludeCommitmentStages($commitment_no_po) === false);

// Test 2: shouldIncludeCommitmentStages with po_required='YES'
echo "\n2. shouldIncludeCommitmentStages() - Standard (po_required='YES')\n";
$commitment_with_po = ['commitment_id' => 2, 'po_required' => 'YES'];
testAssert('Returns true for po_required=YES', shouldIncludeCommitmentStages($commitment_with_po) === true);

// Test 3: shouldIncludeCommitmentStages with no po_required (defaults to YES)
echo "\n3. shouldIncludeCommitmentStages() - Default (no po_required field)\n";
$commitment_default = ['commitment_id' => 3];
testAssert('Returns true when po_required not specified', shouldIncludeCommitmentStages($commitment_default) === true);

// Test 4: shouldIncludeCommitmentStages with null commitment (no commitment created yet)
echo "\n4. shouldIncludeCommitmentStages() - No commitment yet\n";
testAssert('Returns true for null commitment (assumes YES until known)', shouldIncludeCommitmentStages(null) === true);

// Test 5: Verify AWARDED can transition to INVOICE_RECEIVED
echo "\n5. Allowed transitions - AWARDED → INVOICE_RECEIVED\n";
$transitions = allowedTransitions();
$awardedTransitions = $transitions['AWARDED'] ?? [];
testAssert('AWARDED allows INVOICE_RECEIVED transition', in_array('INVOICE_RECEIVED', $awardedTransitions, true));

// Test 6: Verify COMMITMENT_APPROVED can transition to INVOICE_RECEIVED
echo "\n6. Allowed transitions - COMMITMENT_APPROVED → INVOICE_RECEIVED\n";
$commitmentApprovedTransitions = $transitions['COMMITMENT_APPROVED'] ?? [];
testAssert('COMMITMENT_APPROVED allows INVOICE_RECEIVED transition', in_array('INVOICE_RECEIVED', $commitmentApprovedTransitions, true));

// Test 7: Verify COMMITMENT_APPROVED still allows PO_PENDING
echo "\n7. Allowed transitions - COMMITMENT_APPROVED → PO_PENDING (standard path)\n";
testAssert('COMMITMENT_APPROVED allows PO_PENDING transition', in_array('PO_PENDING', $commitmentApprovedTransitions, true));

// Test 8: Verify isSkipRfqPath works with workflow_path
echo "\n8. isSkipRfqPath() - Detect Non-PO path\n";
$nonPoRequest = ['workflow_path' => 'NON_PO_SKIP_RFQ'];
testAssert('isSkipRfqPath recognizes NON_PO_SKIP_RFQ workflow_path', 
    isSkipRfqPath('REGULAR', 0, 'AWARDED', $nonPoRequest));

// Test 9: Verify getWorkflowPath returns correct path
echo "\n9. getWorkflowPath() - Return workflow path\n";
testAssert('Returns NON_PO_SKIP_RFQ for non-PO request', 
    getWorkflowPath($nonPoRequest) === 'NON_PO_SKIP_RFQ');

$standardRequest = ['workflow_path' => 'STANDARD'];
testAssert('Returns STANDARD for standard request', 
    getWorkflowPath($standardRequest) === 'STANDARD');

// Test 10: Verify getWorkflowBadgeHtml creates appropriate badge
echo "\n10. getWorkflowBadgeHtml() - Display badge\n";
$nonPoBadge = getWorkflowBadgeHtml($nonPoRequest);
testAssert('Non-PO badge contains "Non-PO"', strpos($nonPoBadge, 'Non-PO') !== false);

$standardBadge = getWorkflowBadgeHtml($standardRequest);
testAssert('Standard badge contains "Standard"', strpos($standardBadge, 'Standard') !== false);

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
