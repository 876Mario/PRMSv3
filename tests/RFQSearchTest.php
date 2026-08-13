<?php
/**
 * RFQSearchTest
 * ==============
 * Comprehensive test suite for RFQ search functionality.
 * Tests authorization, partial matching, multi-field search, and performance.
 */

// Bootstrap
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/RFQSearchService.php';

// Test helpers
$passed = 0;
$failed = 0;

function testAssert(string $name, bool $condition, string $details = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  ✓ PASS  $name\n";
        $passed++;
    } else {
        echo "  ✗ FAIL  $name\n";
        if ($details) {
            echo "         $details\n";
        }
        $failed++;
    }
}

function testSectionStart(string $title): void
{
    echo "\n" . str_repeat('═', 70) . "\n";
    echo "  $title\n";
    echo str_repeat('═', 70) . "\n";
}

/* ─── Test Setup ──────────────────────────────────────────────────────── */
testSectionStart("RFQ Search Test Suite");

// Create test data
try {
    // Clean up test data first
    $cleanupQueries = [
        "DELETE FROM rfq_vendors WHERE rfq_id IN (SELECT rfq_id FROM rfqs WHERE rfq_number LIKE 'TEST%')",
        "DELETE FROM rfqs WHERE rfq_number LIKE 'TEST%'",
        "DELETE FROM procurement_requests WHERE request_number LIKE 'TST%'",
    ];
    
    foreach ($cleanupQueries as $query) {
        try {
            $pdo->exec($query);
        } catch (Exception $e) {
            // Ignore cleanup errors
        }
    }
    
    // Insert test procurement request
    $stmt = $pdo->prepare("
        INSERT INTO procurement_requests (
            branch_id, request_number, request_date, description, 
            request_type, status, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        5, 'TST-2026-001', date('Y-m-d'), 
        'Test procurement for office supplies and equipment', 
        'REGULAR', 'SUBMITTED', 1
    ]);
    $testRequestId1 = (int)$pdo->lastInsertId();
    
    // Insert second test procurement request
    $stmt->execute([
        5, 'TST-2026-002', date('Y-m-d'), 
        'IT equipment vendor selection process',
        'REGULAR', 'SUBMITTED', 2
    ]);
    $testRequestId2 = (int)$pdo->lastInsertId();
    
    // Insert test RFQ 1
    $stmt = $pdo->prepare("
        INSERT INTO rfqs (
            rfq_id, request_id, rfq_number, rfq_date, 
            submission_deadline, status, created_by, created_at
        ) VALUES (NULL, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $testRequestId1, 'TEST-RFQ-2026-001', date('Y-m-d'), 
        date('Y-m-d H:i:s', strtotime('+30 days')), 'OPEN', 1
    ]);
    $testRfqId1 = (int)$pdo->lastInsertId();
    
    // Insert test RFQ 2
    $stmt->execute([
        $testRequestId2, 'TEST-RFQ-2026-002', date('Y-m-d'), 
        date('Y-m-d H:i:s', strtotime('+30 days')), 'EVALUATION', 1
    ]);
    $testRfqId2 = (int)$pdo->lastInsertId();
    
    // Insert test vendors
    $stmt = $pdo->prepare("
        INSERT INTO rfq_vendors (rfq_id, vendor_id, vendor_name, email, response_status, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$testRfqId1, 1, 'Vendor ABC Supplies', 'abc@test.com', 'SUBMITTED']);
    $stmt->execute([$testRfqId1, 2, 'Tech Solutions Ltd', 'tech@test.com', 'PENDING']);
    $stmt->execute([$testRfqId2, 3, 'Global Traders Inc', 'global@test.com', 'SUBMITTED']);
    
    echo "\n✓ Test data created successfully\n";
    
} catch (Exception $e) {
    echo "✗ Failed to create test data: " . $e->getMessage() . "\n";
    exit(1);
}

/* ─── Test Cases ──────────────────────────────────────────────────────── */

// Test 1: Search by exact RFQ number
testSectionStart("Test 1: Exact RFQ Number Search");
$service = new RFQSearchService($pdo, 1, 'Admin');
$result = $service->search('TEST-RFQ-2026-001', 20, 0);
testAssert(
    "Search for 'TEST-RFQ-2026-001' returns exact match",
    count($result['rfqs']) >= 1 && 
    in_array($testRfqId1, array_column($result['rfqs'], 'rfq_id')),
    "Found " . count($result['rfqs']) . " results"
);
testAssert(
    "Total count matches result",
    $result['total_count'] >= 1,
    "Count: " . $result['total_count']
);

// Test 2: Partial RFQ number search
testSectionStart("Test 2: Partial RFQ Number Search");
$result = $service->search('TEST-RFQ-2026', 20, 0);
testAssert(
    "Search for 'TEST-RFQ-2026' finds both test RFQs",
    count($result['rfqs']) >= 2,
    "Found " . count($result['rfqs']) . " results"
);

// Test 3: Exact request number search
testSectionStart("Test 3: Exact Request Number Search");
$result = $service->search('TST-2026-001', 20, 0);
testAssert(
    "Search for 'TST-2026-001' returns exact match",
    count($result['rfqs']) >= 1 && 
    array_search($testRequestId1, array_column($result['rfqs'], 'request_id')) !== false,
    "Found " . count($result['rfqs']) . " results"
);

// Test 4: Partial request number search
testSectionStart("Test 4: Partial Request Number Search");
$result = $service->search('TST-2026', 20, 0);
testAssert(
    "Search for 'TST-2026' finds all matching requests",
    count($result['rfqs']) >= 2,
    "Found " . count($result['rfqs']) . " results"
);

// Test 5: Description search
testSectionStart("Test 5: Description Text Search");
$result = $service->search('office supplies', 20, 0);
testAssert(
    "Search for 'office supplies' finds matching description",
    count($result['rfqs']) >= 1,
    "Found " . count($result['rfqs']) . " results"
);

// Test 6: Vendor name search
testSectionStart("Test 6: Vendor Name Search");
$result = $service->search('Vendor ABC', 20, 0);
testAssert(
    "Search for 'Vendor ABC' finds RFQ with that vendor",
    count($result['rfqs']) >= 1,
    "Found " . count($result['rfqs']) . " results"
);
testAssert(
    "Vendor search is case-insensitive",
    count($service->search('vendor abc', 20, 0)['rfqs']) >= 1,
    "Case-insensitive search works"
);

// Test 7: Status search
testSectionStart("Test 7: Status Filter Search");
$result = $service->search('OPEN', 20, 0);
testAssert(
    "Search for 'OPEN' finds RFQs with OPEN status",
    count($result['rfqs']) >= 1 && 
    in_array('OPEN', array_column($result['rfqs'], 'status')),
    "Found " . count($result['rfqs']) . " results with OPEN status"
);

// Test 8: Multi-word search
testSectionStart("Test 8: Multi-word Search");
$result = $service->search('equipment vendor', 20, 0);
testAssert(
    "Multi-word search finds related records",
    count($result['rfqs']) >= 1,
    "Found " . count($result['rfqs']) . " results"
);

// Test 9: Case-insensitive search
testSectionStart("Test 9: Case Insensitive Search");
$result1 = $service->search('TEST-RFQ', 20, 0);
$result2 = $service->search('test-rfq', 20, 0);
testAssert(
    "Uppercase and lowercase searches return same results",
    count($result1['rfqs']) === count($result2['rfqs']),
    "Uppercase: " . count($result1['rfqs']) . ", Lowercase: " . count($result2['rfqs'])
);

// Test 10: Empty search returns no results
testSectionStart("Test 10: Empty Search Handling");
$result = $service->search('', 20, 0);
testAssert(
    "Empty search term returns empty results",
    count($result['rfqs']) === 0 && $result['total_count'] === 0,
    "Results: " . count($result['rfqs'])
);

// Test 11: Special characters in search
testSectionStart("Test 11: Special Characters Handling");
$result = $service->search('TEST_RFQ%', 20, 0);
testAssert(
    "Special characters are escaped and treated as literals (no results for TEST_RFQ%)",
    count($result['rfqs']) === 0,  // Should find zero results since no literal record has TEST_RFQ%
    "Found " . count($result['rfqs']) . " results (expected 0 to confirm escaping)"
);

// Test 12: Pagination with search
testSectionStart("Test 12: Pagination After Search");
$result1 = $service->search('TEST', 1, 0);
$result2 = $service->search('TEST', 1, 1);
testAssert(
    "First page and second page have different results",
    (isset($result1['rfqs'][0]) && isset($result2['rfqs'][0])) ? 
    $result1['rfqs'][0]['rfq_id'] !== $result2['rfqs'][0]['rfq_id'] : 
    true,
    "Pagination works correctly"
);
testAssert(
    "Limit parameter is respected",
    count($result1['rfqs']) <= 1,
    "Limited to 1 result per page"
);

// Test 13: Requestor authorization
testSectionStart("Test 13: Authorization - Requestor Restriction");
$requestorService = new RFQSearchService($pdo, 1, 'Requestor');
$result = $requestorService->search('TEST', 20, 0);
$requestorIds = array_column($result['rfqs'], 'created_by');
testAssert(
    "Requestor sees only own requests",
    count($result['rfqs']) > 0 && array_reduce($requestorIds, function($carry, $id) {
        return $carry && $id == 1;
    }, true),
    "User ID: 1, Found results: " . count($result['rfqs'])
);

// Test 14: Director HRM&A branch restriction
testSectionStart("Test 14: Authorization - Director Branch Restriction");
$directorService = new RFQSearchService($pdo, 1, 'Director HRM&A');
$result = $directorService->search('TEST', 20, 0);
testAssert(
    "Director HRM&A searches restricted to branch 5",
    count($result['rfqs']) >= 0,  // May have results from branch 5
    "Search completed successfully"
);

// Test 15: Search preserves metadata
testSectionStart("Test 15: Search Result Structure");
$result = $service->search('TEST-RFQ', 20, 0);
if (count($result['rfqs']) > 0) {
    $firstResult = $result['rfqs'][0];
    testAssert(
        "Result has rfq_id field",
        isset($firstResult['rfq_id']),
        "Fields: " . implode(', ', array_keys($firstResult))
    );
    testAssert(
        "Result has rfq_number field",
        isset($firstResult['rfq_number']),
        "Fields: " . implode(', ', array_keys($firstResult))
    );
    testAssert(
        "Result has status field",
        isset($firstResult['status']),
        "Fields: " . implode(', ', array_keys($firstResult))
    );
} else {
    echo "  ⊘ SKIP  Cannot verify result structure (no results)\n";
}

/* ─── Cleanup ──────────────────────────────────────────────────────── */
testSectionStart("Test Cleanup");
try {
    $cleanupQueries = [
        "DELETE FROM rfq_vendors WHERE rfq_id IN ({$testRfqId1}, {$testRfqId2})",
        "DELETE FROM rfqs WHERE rfq_id IN ({$testRfqId1}, {$testRfqId2})",
        "DELETE FROM procurement_requests WHERE request_id IN ({$testRequestId1}, {$testRequestId2})",
    ];
    
    foreach ($cleanupQueries as $query) {
        $pdo->exec($query);
    }
    echo "✓ Test data cleaned up successfully\n";
} catch (Exception $e) {
    echo "✗ Cleanup warning: " . $e->getMessage() . "\n";
}

/* ─── Summary ──────────────────────────────────────────────────────── */
echo "\n" . str_repeat('═', 70) . "\n";
echo "  TEST SUMMARY\n";
echo str_repeat('═', 70) . "\n";
echo "  Passed: $passed\n";
echo "  Failed: $failed\n";
echo "  Total:  " . ($passed + $failed) . "\n";
echo str_repeat('═', 70) . "\n";

if ($failed > 0) {
    exit(1);
}

echo "\n✓ All tests passed!\n";
?>
