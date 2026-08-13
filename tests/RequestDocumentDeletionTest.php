<?php
/**
 * tests/RequestDocumentDeletionTest.php
 * ======================================
 * Tests for the request-document soft-delete permission gate:
 *  - isFinalizedRequestStatus() correctly identifies finalized requests
 *  - Non-finalized statuses do not require the elevated permission
 */

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SESSION = [];

require_once __DIR__ . '/../config/workflow.php';

$passed = 0;
$failed = 0;

function rdAssert(string $name, bool $cond): void
{
    global $passed, $failed;
    if ($cond) { echo "  PASS  {$name}\n"; $passed++; }
    else        { echo "  FAIL  {$name}\n"; $failed++; }
}

echo "\n=== RequestDocumentDeletionTest ===\n";

rdAssert('COMPLETED is a finalized status', isFinalizedRequestStatus('COMPLETED') === true);
rdAssert('completed (lowercase) is a finalized status', isFinalizedRequestStatus('completed') === true);
rdAssert('SUBMITTED is not a finalized status', isFinalizedRequestStatus('SUBMITTED') === false);
rdAssert('DRAFT is not a finalized status', isFinalizedRequestStatus('DRAFT') === false);
rdAssert('AWARDED is not a finalized status', isFinalizedRequestStatus('AWARDED') === false);
rdAssert('CANCELLED is not a finalized status (documents still deletable by standard permission)', isFinalizedRequestStatus('CANCELLED') === false);

echo "\n{$passed} passed, {$failed} failed\n";

if ($failed > 0) {
    exit(1);
}
