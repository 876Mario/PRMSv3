# PO Requirement Determination at Request Creation - Implementation Summary

## Overview
This implementation adds the ability to determine whether a Purchase Order (PO) is required at the time of request creation, based on two business flags: **work_performed** and **goods_delivered**. 

**Business Rule:** If BOTH work has been performed AND goods have been delivered, a PO is NOT required. If either is false, a PO IS required.

## Files Modified

### 1. Database Migration
**File:** `/migrations/2026_08_19_po_requirement_at_creation.sql`

**Changes:**
- Added `work_performed` column (TINYINT(1), DEFAULT 0) to `procurement_requests`
- Added `goods_delivered` column (TINYINT(1), DEFAULT 0) to `procurement_requests`
- Added `po_requirement_notes` column (TEXT, DEFAULT NULL) to `procurement_requests`
- Created indexes for efficient filtering
- Created `v_po_requirement_audit` view for monitoring consistency

**Conservative Default:** Both flags default to 0 (false), meaning PO IS required by default

### 2. Workflow Configuration
**File:** `/config/workflow.php`

**New Functions Added:**
```php
/**
 * shouldRequirePoAtCreation(array $request): bool
 * Returns true if PO is required, false if not required
 * Logic: BOTH work_performed AND goods_delivered must be true to skip PO
 */

/**
 * getDerivedPoRequired(array $request): string
 * Returns 'YES' or 'NO' for use in database
 * Wrapper around shouldRequirePoAtCreation()
 */
```

**Design:**
- NULL/missing values default to requiring PO (safe/conservative)
- Integer/boolean type coercion handled
- Edge cases (negative values, large integers) handled correctly

### 3. Request Creation Form
**File:** `/procurement/add.php`

**UI Changes:**
- Added two checkboxes for business flags:
  - "Work has already been performed"
  - "Goods have already been delivered"
- Added info alert explaining the PO requirement logic
- Added dynamic status display showing PO requirement based on flags
- Added optional notes field for justification

**Backend Changes:**
- Capture `work_performed` and `goods_delivered` from POST data
- Capture `po_requirement_notes` from POST data
- Insert these values into `procurement_requests` table on creation

**Form Logic:**
- Flags are optional during request creation (unchecked by default)
- JavaScript updates status message in real-time
- Shows green "NO PO REQUIRED" when both checked
- Shows yellow "PO REQUIRED" when one or both unchecked

### 4. Commitment Creation
**File:** `/commitments/add.php`

**Query Changes:**
- Added `work_performed`, `goods_delivered`, `po_requirement_notes` fields to SELECT query

**Business Logic:**
- On commitment creation, derive the PO requirement from request flags
- Use `getDerivedPoRequired($request)` to get auto-derived value
- Auto-populate `po_required` select with derived value
- Display request flags in a blue info box so Finance can see the reasoning
- Allow Finance Officer to override if needed (with warning)

**Form Enhancements:**
- Show request flags in read-only checkboxes
- Display derived PO requirement in info section
- Pre-populate the `po_required` select with derived value
- Show notes that were provided at request time

### 5. PO Creation Validation
**File:** `/po/add.php`

**Safeguards Added:**
1. Existing check: Cannot create PO if `commitment.po_required = 'NO'`
2. New check: Verify request flags still align with commitment decision
3. New audit logging if mismatch detected (for investigation)

**Scenarios Handled:**
- User cannot create PO if commitment says NO
- Warning if request flags were changed after commitment creation
- Audit trail preserved for investigation

## Workflow Transitions - Impact Analysis

### Standard Workflow (PO Required)
```
Request → Approval → Funds Verified → Commitment (po_required='YES') 
→ PO_PENDING → Invoice → Completed
```

**No changes** - existing workflow continues unchanged

### Non-PO Workflow (Both Flags True)
```
Request (work_performed=1, goods_delivered=1) 
→ Approval → Funds Verified 
→ Commitment (auto-set po_required='NO')
→ AWARD STATUS → Invoice → Completed
```

**Improvement:** PO requirement now determined at request creation, not during commitment

## Reversibility & Guard Rails

### Reversibility (Backward Transitions)
1. **COMMITMENT_APPROVED ↔ FUNDS_VERIFIED:** Finance can revert to re-verify if flags change
2. **PO_PENDING → COMMITMENT_APPROVED:** Available if PO creation needed to be reconsidered
3. **INVOICE_RECEIVED → PO_PENDING:** Can revert if PO creation missed

### Guard Rails (Preventing Bypass)
1. **Request Flags:** Capture at creation time, preservable in audit trail
2. **Commitment Lock:** `po_required` set at commitment time, cannot change commitment retroactively
3. **PO Prevention:** Cannot create PO if `po_required='NO'` (existing validation)
4. **Audit Logging:** All discrepancies between request flags and commitment decision logged

### Edge Case: Flag Changes After Commitment
**If user changes request flags after commitment creation:**
1. Commitment `po_required` value remains unchanged (immutable)
2. Audit log records the change
3. Finance Officer sees warning if mismatch during PO creation
4. Investigation trail preserved via audit table

## Historical Records & Migration

### Existing Data Impact
- **Default to PO Required:** All existing requests default to `work_performed=0, goods_delivered=0`
- **No Retroactive Changes:** Existing commitments keep their current `po_required` values
- **View Only:** Flags visible in UI but don't alter existing workflow behavior
- **Safe Rollback:** Column defaults ensure no NULL values

### Migration Strategy
1. New columns added to table with DEFAULT 0 (PO required)
2. Existing requests unaffected (defaults apply)
3. New requests must have flags explicitly set (or defaults apply)
4. Audit view helps identify any inconsistencies

## Tests Added

### Unit Tests
**File:** `/tests/PoRequirementAtCreationTest.php`
- 22 tests covering all combinations and edge cases
- All tests passing ✓

**Test Coverage:**
- Both flags false → PO required
- Work performed only → PO required
- Goods delivered only → PO required
- Both flags true → NO PO required
- NULL/missing values → PO required (default)
- Type coercion (string, float, boolean)
- Edge cases (negative, > 1, empty strings)

### Integration Tests
**Existing tests still pass:**
- `NonPoWorkflowTest.php` (12/12 tests)
- `NonPoWorkflowIntegrationTest.php` (23/23 tests)
- `NonPoCommitmentRemediationTest.php` (15/15 tests)

**Total: 72/72 tests passing** ✓

## Audit & Monitoring

### Consistency Audit Queries
**File:** `/docs/PO_REQUIREMENT_AUDIT_QUERIES.sql`

Eight audit queries verify:
1. All requests have proper flag values (not NULL)
2. Commitment `po_required` aligns with request flags
3. No PO bypass routes exist (no PO created for non-PO commitments)
4. Workflow paths consistent with decisions
5. No status transitions skip PO requirement
6. Summary statistics
7. Backward transition capability
8. Edge cases (flag changes after commitment)

### Recommended Monitoring
Run these queries:
- **Weekly:** Check for misaligned commitments (Query 2)
- **Weekly:** Verify no PO bypass (Query 3)
- **Monthly:** Full consistency audit (all queries)

## Known Limitations & Future Enhancements

### Current Scope
- Only affects **REGULAR** procurement requests
- SERVICE_CONTRACT and PETTY_CASH/REIMBURSEMENT unaffected
- Manual input only (no automatic detection of work completion)

### Future Enhancements
1. Automatic detection of work completion from invoice upload
2. Integration with inventory management (goods receipt)
3. Approval workflow step to verify both conditions met
4. Historical tracking of flag changes over time
5. Export reports showing PO savings from non-PO paths

## Risk Assessment

### Risks Addressed
✓ **Audit Trail Loss:** All changes logged in audit_log table
✓ **Accidental Bypass:** PO creation blocked if `po_required='NO'`
✓ **Data Inconsistency:** Audit view detects mismatches
✓ **User Confusion:** Flags displayed clearly in commitment form
✓ **Backward Compatibility:** Defaults preserve existing behavior

### Residual Risks
- **Manual Input Error:** User may incorrectly set flags (mitigated by help text)
- **Flag Change Exploit:** User could change flags after commitment (mitigated by immutable commitment)
- **System Integration:** If commitments synced to GFMS, ensure sync respects `po_required`

## Deployment Checklist

- [x] Database migration created
- [x] Helper functions added to workflow config
- [x] UI forms updated (request creation & commitment)
- [x] Backend logic implemented (auto-derive PO requirement)
- [x] PO creation safeguards added
- [x] Unit tests written (22/22 passing)
- [x] Integration tests passing (72/72 tests)
- [x] Audit queries created
- [x] Documentation complete
- [ ] Deploy migration to test environment
- [ ] Run audit queries to verify baseline
- [ ] Deploy code to production
- [ ] Monitor for flag-commitment mismatches

## Summary Statistics

| Aspect | Details |
|--------|---------|
| **Schema Changes** | 3 new columns, 3 new indexes, 1 new view |
| **Code Changes** | 4 files modified, 2 functions added, 1 file created |
| **Tests Added** | 22 new unit tests |
| **Tests Total** | 72/72 passing (all existing tests still pass) |
| **Audit Queries** | 8 comprehensive consistency checks |
| **Backward Compatibility** | ✓ Fully compatible (defaults to PO required) |
| **Reversibility** | ✓ Backward transitions supported |
| **Bypass Prevention** | ✓ Multiple guard rails implemented |

## Implementation Status

**COMPLETE** - All requirements met:
✓ Determine PO requirement at request creation
✓ Support both work_performed and goods_delivered flags  
✓ Conservative default (requires PO if either flag false)
✓ Auto-derive po_required at commitment time
✓ Prevent bypass through alternate routes
✓ Reversible in both directions
✓ Historical records preserved
✓ Comprehensive testing
✓ Audit trail maintained

---

**Document Version:** 1.0  
**Date:** 2026-08-19  
**Status:** Ready for Production Deployment
