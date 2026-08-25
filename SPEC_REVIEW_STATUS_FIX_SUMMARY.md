# Fix for spec_review_status Column Error

## Problem Statement
Users were encountering the error:
```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'spec_review_status' in 'SET'
```

## Root Cause
The issue occurred due to a mismatch between database triggers/stored procedures and the actual column names in the `rfqs` table:

1. **July 31, 2026 migrations** created:
   - Columns: `spec_review_status`, `spec_reviewer_id`, `spec_reviewed_at`, `spec_review_comments`
   - Triggers: `trg_initialize_rfq_approval_workflow`, `trg_require_quote_approval_for_commitment`
   - Stored procedures: `sp_approve_rfq_spec_review`, `sp_reject_rfq_spec_review`

2. **August 21, 2026 migration** renamed these columns to:
   - `requestor_spec_review_status`
   - `requestor_reviewer_id`
   - `requestor_reviewed_at`
   - `requestor_review_comments`

3. **The problem**: The August 21 migration renamed the columns but did NOT update the triggers and stored procedures that were already created in the database. These database objects continued to reference the old column names, causing "Column not found" errors when executed.

## Solution
Created a new migration (`2026_08_25_fix_rfq_triggers_column_names.sql`) that:

1. **Drops and recreates triggers** with correct column names:
   - `trg_initialize_rfq_approval_workflow` - Now uses `requestor_spec_review_status`
   - `trg_require_quote_approval_for_commitment` - Now uses `requestor_spec_review_status`

2. **Drops old stored procedures and creates new ones** with correct names and column references:
   - Drops: `sp_approve_rfq_spec_review`, `sp_reject_rfq_spec_review`
   - Creates: `sp_approve_rfq_requestor_review`, `sp_reject_rfq_requestor_review`
   - Both new procedures use `requestor_spec_review_status` and related columns
   - Procedure signatures updated to match August 21 migration's requirements (added `p_quote_id` parameter)

3. **Updated documentation** to reflect correct column and index names:
   - `RFQ_QUOTE_APPROVAL_WORKFLOW.md`
   - `RFQ_APPROVAL_WORKFLOW_DEPLOYMENT.md`

## Key Design Decisions

### Why Not Modify Old Migration Files?
We deliberately chose NOT to modify the July 31 migration files because:
- They have already been executed on production databases
- Modifying them would break the migration chain for fresh installations
- The August 21 migration's `CHANGE COLUMN` statement expects the old column names to exist

### Migration Strategy
The new migration preserves migration chain integrity:
- Old migrations remain unchanged (preserve history)
- August 21 migration renames the columns (as before)
- New August 25 migration fixes the database objects created by old migrations
- Fresh installs: All migrations run in sequence → correct final state
- Existing databases: Only new migration runs → updates triggers/procedures to use new columns

### Transaction Handling
- Removed outer transaction from migration (MySQL DDL causes implicit commits)
- Kept inner transactions in stored procedures (ensures atomicity of multi-statement operations)
- Matches pattern used in August 21 migration

## Files Changed
1. `migrations/2026_08_25_fix_rfq_triggers_column_names.sql` (NEW)
2. `RFQ_QUOTE_APPROVAL_WORKFLOW.md` (documentation update)
3. `RFQ_APPROVAL_WORKFLOW_DEPLOYMENT.md` (documentation update)

## Verification
✅ All PHP code already uses correct column name `requestor_spec_review_status`
✅ All services already reference correct column names
✅ No remaining references to old column name in application code
✅ Migration is idempotent (can be run multiple times safely)
✅ Documentation updated to reflect correct column names

## How to Apply
Run the migration:
```bash
php migrations/apply.php 2026_08_25_fix_rfq_triggers_column_names.sql
```

Or through the admin interface if available.

## Expected Outcome
After applying this migration:
- All RFQ approval workflows will function correctly
- Triggers will properly initialize approval statuses on new RFQs
- Stored procedures will correctly update approval statuses
- Commitment creation validations will work properly
- No more "Column not found" errors

## Testing Recommendations
After applying the migration, test:
1. Create a new RFQ → verify approval statuses initialize correctly
2. Submit requestor specification review → verify stored procedure executes without error
3. Attempt to create a commitment → verify trigger checks work correctly
4. Check trigger definitions in database:
   ```sql
   SHOW CREATE TRIGGER trg_initialize_rfq_approval_workflow;
   SHOW CREATE TRIGGER trg_require_quote_approval_for_commitment;
   ```
5. Check procedure definitions:
   ```sql
   SHOW CREATE PROCEDURE sp_approve_rfq_requestor_review;
   SHOW CREATE PROCEDURE sp_reject_rfq_requestor_review;
   ```
