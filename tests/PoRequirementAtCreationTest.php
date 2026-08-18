<?php
/**
 * PoRequirementAtCreationTest
 * ==========================
 * Tests for PO requirement determination at request creation time.
 *
 * Verifies:
 * - shouldRequirePoAtCreation() returns correct boolean
 * - getDerivedPoRequired() returns correct string
 * - work_performed and goods_delivered flags control PO requirement
 * - NULL/missing values default to requiring PO
 * - Edge cases with incomplete data handled safely
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
echo "\n=== PoRequirementAtCreationTest ===\n";

// Test 1: Both flags false (default)
echo "\n1. shouldRequirePoAtCreation() - Both flags false\n";
$request_both_false = [
    'work_performed' => 0,
    'goods_delivered' => 0
];
testAssert('Returns true (PO required) when both flags false', 
    shouldRequirePoAtCreation($request_both_false) === true);

// Test 2: Work performed but goods not delivered
echo "\n2. shouldRequirePoAtCreation() - Work performed, goods not delivered\n";
$request_work_only = [
    'work_performed' => 1,
    'goods_delivered' => 0
];
testAssert('Returns true (PO required) when only work performed', 
    shouldRequirePoAtCreation($request_work_only) === true);

// Test 3: Goods delivered but work not performed
echo "\n3. shouldRequirePoAtCreation() - Goods delivered, work not performed\n";
$request_goods_only = [
    'work_performed' => 0,
    'goods_delivered' => 1
];
testAssert('Returns true (PO required) when only goods delivered', 
    shouldRequirePoAtCreation($request_goods_only) === true);

// Test 4: Both flags true (NO PO required)
echo "\n4. shouldRequirePoAtCreation() - Both flags true\n";
$request_both_true = [
    'work_performed' => 1,
    'goods_delivered' => 1
];
testAssert('Returns false (NO PO required) when both flags true', 
    shouldRequirePoAtCreation($request_both_true) === false);

// Test 5: Missing work_performed field (defaults to 0)
echo "\n5. shouldRequirePoAtCreation() - Missing work_performed (defaults to false)\n";
$request_missing_work = [
    'goods_delivered' => 1
];
testAssert('Returns true (PO required) when work_performed missing', 
    shouldRequirePoAtCreation($request_missing_work) === true);

// Test 6: Missing goods_delivered field (defaults to 0)
echo "\n6. shouldRequirePoAtCreation() - Missing goods_delivered (defaults to false)\n";
$request_missing_goods = [
    'work_performed' => 1
];
testAssert('Returns true (PO required) when goods_delivered missing', 
    shouldRequirePoAtCreation($request_missing_goods) === true);

// Test 7: Both fields missing (empty request)
echo "\n7. shouldRequirePoAtCreation() - Both fields missing\n";
$request_empty = [];
testAssert('Returns true (PO required) when both fields missing', 
    shouldRequirePoAtCreation($request_empty) === true);

// Test 8: NULL values
echo "\n8. shouldRequirePoAtCreation() - NULL values\n";
$request_null = [
    'work_performed' => null,
    'goods_delivered' => null
];
testAssert('Returns true (PO required) when both fields NULL', 
    shouldRequirePoAtCreation($request_null) === true);

// Test 9: String values that should convert to boolean
echo "\n9. shouldRequirePoAtCreation() - String numeric values\n";
$request_strings = [
    'work_performed' => '1',
    'goods_delivered' => '1'
];
testAssert('Returns false when string "1" values converted to true', 
    shouldRequirePoAtCreation($request_strings) === false);

// Test 10: Mixed boolean and integer types
echo "\n10. shouldRequirePoAtCreation() - Mixed types\n";
$request_mixed = [
    'work_performed' => true,
    'goods_delivered' => 1
];
testAssert('Returns false when mixed boolean and integer types', 
    shouldRequirePoAtCreation($request_mixed) === false);

/* ─── getDerivedPoRequired() Tests ─────────────────────────────────── */

// Test 11: getDerivedPoRequired() returns 'YES' when PO required
echo "\n11. getDerivedPoRequired() - Returns 'YES' string\n";
testAssert('Returns "YES" when PO required', 
    getDerivedPoRequired($request_both_false) === 'YES');

// Test 12: getDerivedPoRequired() returns 'NO' when PO not required
echo "\n12. getDerivedPoRequired() - Returns 'NO' string\n";
testAssert('Returns "NO" when PO not required', 
    getDerivedPoRequired($request_both_true) === 'NO');

// Test 13: getDerivedPoRequired() default case
echo "\n13. getDerivedPoRequired() - Default behavior\n";
testAssert('Returns "YES" for empty request (default)', 
    getDerivedPoRequired([]) === 'YES');

/* ─── Edge Cases ─────────────────────────────────────────────────────── */

// Test 14: Integer values outside 0/1 range
echo "\n14. shouldRequirePoAtCreation() - Edge case: values > 1\n";
$request_large_int = [
    'work_performed' => 999,
    'goods_delivered' => 888
];
testAssert('Treats any non-zero int as true', 
    shouldRequirePoAtCreation($request_large_int) === false);

// Test 15: Negative integer values
echo "\n15. shouldRequirePoAtCreation() - Edge case: negative values\n";
$request_negative = [
    'work_performed' => -1,
    'goods_delivered' => -1
];
testAssert('Treats negative int as true (non-zero)', 
    shouldRequirePoAtCreation($request_negative) === false);

// Test 16: Empty string values
echo "\n16. shouldRequirePoAtCreation() - Edge case: empty strings\n";
$request_empty_strings = [
    'work_performed' => '',
    'goods_delivered' => ''
];
testAssert('Empty strings convert to false', 
    shouldRequirePoAtCreation($request_empty_strings) === true);

// Test 17: Decimal/float values
echo "\n17. shouldRequirePoAtCreation() - Edge case: float values\n";
$request_floats = [
    'work_performed' => 1.5,
    'goods_delivered' => 1.0
];
testAssert('Float 1.5 and 1.0 are truthy (both > 0)', 
    shouldRequirePoAtCreation($request_floats) === false);

// Test 18: Boolean values directly
echo "\n18. shouldRequirePoAtCreation() - Boolean input\n";
$request_booleans = [
    'work_performed' => false,
    'goods_delivered' => true
];
testAssert('Boolean false and true handled correctly', 
    shouldRequirePoAtCreation($request_booleans) === true);

// Test 19: String boolean representations (should NOT convert)
echo "\n19. shouldRequirePoAtCreation() - String 'true'/'false'\n";
$request_string_booleans = [
    'work_performed' => 'true',
    'goods_delivered' => 'false'
];
// 'true' string casts to int 0, 'false' string casts to int 0
testAssert('String "true" and "false" convert to int 0 (falsy)', 
    shouldRequirePoAtCreation($request_string_booleans) === true);

/* ─── Business Logic Verification ─────────────────────────────────── */

// Test 20: Verify exact business rule
echo "\n20. Business Rule Verification\n";
testAssert('Exact rule: Both true → NO PO required', 
    getDerivedPoRequired(['work_performed' => 1, 'goods_delivered' => 1]) === 'NO');

testAssert('Exact rule: Either false → PO required', 
    getDerivedPoRequired(['work_performed' => 1, 'goods_delivered' => 0]) === 'YES');

testAssert('Exact rule: Both false → PO required', 
    getDerivedPoRequired(['work_performed' => 0, 'goods_delivered' => 0]) === 'YES');

/* ─── Summary ─────────────────────────────────────────────────────── */
echo "\n" . str_repeat("=", 60) . "\n";
echo "RESULTS: $passed passed, $failed failed\n";
if ($failed === 0) {
    echo "✓ ALL TESTS PASSED\n";
} else {
    echo "✗ SOME TESTS FAILED\n";
    exit(1);
}
echo str_repeat("=", 60) . "\n";

?>
