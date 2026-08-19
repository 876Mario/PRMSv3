# CRON OVERDUE ALERT RECIPIENT FILTERING - DEPLOYMENT GUIDE

## Executive Summary

This implementation fixes critical issues where procurement and inventory overdue alerts were being sent to **all users** instead of **only intended recipients**. The root causes and solutions are:

| Issue | Root Cause | Solution |
|-------|-----------|----------|
| Procurement alerts to all Procurement Officers | No branch filtering in recipient query | Branch-aware recipient selection via configuration table |
| Inventory alerts to hardcoded admin only | Single admin email hardcoded | Dynamic query of all Property Management Officers |
| No deduplication at recipient level | Alerts created first, then filtered | Recipients selected first, then notifications created |
| Concurrent cron runs possible | No locking mechanism | Exclusive execution locks per cron job |
| No audit trail of decisions | No logging of recipient selection | Complete audit trail via `cron_recipient_audit` table |

---

## Pre-Deployment Checklist

- [ ] Database backup created
- [ ] Tested migration on staging environment
- [ ] Reviewed all table structures (no schema conflicts)
- [ ] Confirmed PHP 7.4+ environment
- [ ] PDO with InnoDB support confirmed
- [ ] `sendMail()` function available from config/mailer.php
- [ ] Cron user has database write permissions

---

## Deployment Steps

### Step 1: Apply Database Migration

```bash
# On production server
cd /var/www/prms
mysql -u <dbuser> -p <dbname> < migrations/2026_08_19_cron_alert_recipient_configuration.sql
```

**Verification:**
```sql
-- Verify all tables were created
SHOW TABLES LIKE 'cron_%';
SHOW TABLES LIKE '%alert_recipients';

-- Expected output:
-- cron_execution_locks
-- cron_execution_log
-- cron_recipient_audit
-- procurement_alert_recipients
-- inventory_alert_recipients
```

### Step 2: Deploy Updated Cron Files

```bash
# Backup originals
cp cron/overdue_alerts.php cron/overdue_alerts.php.bak
cp cron/inventory_alerts.php cron/inventory_alerts.php.bak

# Deploy new versions
cp /path/to/new/overdue_alerts.php cron/
cp /path/to/new/inventory_alerts.php cron/

# Verify CronAuditService is deployed
ls -la services/CronAuditService.php
```

### Step 3: Verify Cron Permissions

```bash
# Ensure cron user can access required files
chown www-data:www-data cron/*.php
chmod 755 cron/*.php

# Test cron user can connect to database
sudo -u www-data php -r "require 'config/db.php'; echo 'DB OK';"
```

### Step 4: Configure Default Alert Recipients

The migration seeds default recipients:
- **Procurement**: All Branch Heads in their respective branches
- **Inventory**: All Property Management Officers (all locations)

**To verify seeded recipients:**
```sql
SELECT * FROM procurement_alert_recipients;
SELECT * FROM inventory_alert_recipients;
```

**To customize for your organization:**
```sql
-- Add specific user as procurement alert recipient
INSERT INTO procurement_alert_recipients
    (branch_id, recipient_type, recipient_user_id, is_active, notes)
VALUES (1, 'USER', 42, 1, 'John Doe - Procurement Officer for Branch 1');

-- Add location-specific inventory alerts
INSERT INTO inventory_alert_recipients
    (location_id, recipient_type, recipient_role_id, is_active, alert_types)
VALUES (5, 'ROLE', 13, 1, 'EXPIRED,EXPIRING_7');  -- Only PMO, expired + 7day alerts
```

### Step 5: Run First Cron Job Manually (Dry-Run)

```bash
# Test procurement overdue alerts (should not send real emails on first run)
php cron/overdue_alerts.php

# Expected output:
# [2026-08-19 15:30:45] Overdue-alerts cron started. Reminder: 3d  Escalation: 7d
# [15:30:45] Found 2 stuck request(s).
# [15:30:45] Reminder sent → user 42 (John Smith) for request 101
# [2026-08-19 15:30:47] Overdue-alerts cron completed. Created 1/1 notifications.
```

### Step 6: Monitor Execution Logs

```sql
-- Check cron execution history
SELECT id, cron_name, started_at, status, requests_processed, recipients_found, 
       notifications_created, duration_ms
FROM cron_execution_log
ORDER BY started_at DESC
LIMIT 10;

-- Check recipient selection audit trail
SELECT execution_id, request_id, request_ref, recipient_user_id, recipient_reason, deduped
FROM cron_recipient_audit
WHERE DATE(created_at) = CURDATE()
ORDER BY created_at DESC;

-- Check for any failures
SELECT * FROM cron_execution_log WHERE status IN ('FAILED', 'PARTIAL_FAILURE');
```

### Step 7: Configure Cron Schedule

Edit your crontab (typically `/etc/cron.d/prms` or `crontab -e`):

```cron
# Procurement overdue alerts - daily at 08:00
0 8 * * * www-data /usr/bin/php /var/www/prms/cron/overdue_alerts.php >> /var/log/prms-cron-alerts.log 2>&1

# Inventory alerts - daily at 06:00  
0 6 * * * www-data /usr/bin/php /var/www/prms/cron/inventory_alerts.php >> /var/log/prms-cron-alerts.log 2>&1
```

**Verify crontab was added:**
```bash
crontab -l -u www-data | grep -E "overdue_alerts|inventory_alerts"
```

---

## Post-Deployment Verification

### Manual QA Checklist

#### Test Case 1: Procurement Alerts - Branch Filtering
```sql
-- 1. Create test request for Branch A
INSERT INTO procurement_requests 
  (request_number, status, created_by, branch_id, estimated_value, currency)
VALUES ('TEST-BRANCH-A', 'SUBMITTED', 1, 1, 100000, 'JMD');

-- 2. Update created_at to simulate old request (3+ days)
UPDATE procurement_requests 
SET updated_at = DATE_SUB(NOW(), INTERVAL 4 DAY)
WHERE request_number = 'TEST-BRANCH-A';

-- 3. Run cron
php cron/overdue_alerts.php 2>&1 | grep TEST-BRANCH-A

-- 4. Verify alert ONLY sent to Branch A recipients, not Branch B
SELECT COUNT(*) FROM email_notification_log
WHERE recipient_email LIKE '%branch-b%' AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE);
-- Should be 0 (no alerts to Branch B users)
```

**Expected Result:**
- Alert sent only to Branch A's configured recipients (Branch Head)
- Branch B users receive NO alert
- Audit trail shows reason for each recipient

#### Test Case 2: Inventory Alerts - PMO Recipients
```sql
-- 1. Check Property Management Officers are receiving alerts
SELECT u.user_id, u.full_name, u.email, r.name
FROM users u
JOIN roles r ON u.role_id = r.id
WHERE r.name = 'Property Management Officer' AND u.is_active = 1;

-- 2. Run inventory cron
php cron/inventory_alerts.php

-- 3. Check audit trail
SELECT recipient_user_id, recipient_reason FROM cron_recipient_audit
WHERE execution_id = (SELECT MAX(id) FROM cron_execution_log WHERE cron_name = 'inventory_alerts')
LIMIT 5;
-- Should show PMO users with reason "Property Management Officer"
```

**Expected Result:**
- All active PMOs receive inventory alerts
- Admin user receives NO alert (not in configured recipients)
- Alert email contains item details (reorder level, expiry date, etc.)

#### Test Case 3: Deduplication
```sql
-- 1. Run cron twice on same day
php cron/overdue_alerts.php
php cron/overdue_alerts.php  # Second run

-- 2. Check reminder_log
SELECT request_id, user_id, reminder_type, DATE(sent_at) as sent_date, COUNT(*) as count
FROM reminder_log
GROUP BY request_id, user_id, reminder_type, DATE(sent_at)
HAVING count > 1;
-- Should be empty (no duplicates same day)
```

**Expected Result:**
- Second cron run detects already-sent reminders and skips them
- `reminder_log` has exactly one entry per user/request/type per day
- No duplicate emails sent

#### Test Case 4: Lock Mechanism
```bash
# 1. Start first cron in background
php cron/overdue_alerts.php &
sleep 1

# 2. Try to start second cron (should exit immediately)
timeout 5 php cron/overdue_alerts.php
echo "Exit code: $?"  # Should be 1 (failed to acquire lock)

# 3. Wait for first to complete and check no lingering locks
wait
sleep 2
mysql -u <user> -p<pass> <db> -e "SELECT COUNT(*) FROM cron_execution_locks WHERE cron_name='overdue_alerts';"
# Should be 0 (lock released)
```

**Expected Result:**
- Lock prevents concurrent execution (exit code 1)
- Lock is released after completion
- No orphaned locks remain

#### Test Case 5: Inactive/Deleted Recipients
```sql
-- 1. Deactivate a recipient
UPDATE users SET is_active = 0 WHERE user_id = 42;

-- 2. Run cron
php cron/overdue_alerts.php

-- 3. Check audit trail - inactive user should NOT appear
SELECT COUNT(*) FROM cron_recipient_audit
WHERE recipient_user_id = 42 
  AND execution_id = (SELECT MAX(id) FROM cron_execution_log WHERE cron_name = 'overdue_alerts');
-- Should be 0

-- 4. Reactivate for other tests
UPDATE users SET is_active = 1 WHERE user_id = 42;
```

**Expected Result:**
- Inactive users are never included in recipient queries
- Deleted users are cascaded from configuration tables
- No orphaned audit entries for non-existent users

---

## Automated Testing

### Running Unit Tests

```bash
cd /var/www/prms

# Run specific test class
php tests/CronAlertRecipientFilteringTest.php

# Expected output:
# [SETUP] Creating test data...
# [SETUP] Created test branch 101, location 201, users 1001, 1002, 1003
# [TEST 1] Procurement: Branch Head receives alert for their branch
# [✓] PASS: Branch Head 1001 found in recipients for branch 101
# ...
# ============================================================
# Test Results: 7 PASSED, 0 FAILED
# ============================================================
```

### Continuous Monitoring

Add to your monitoring/logging system:

```bash
# Monitor cron execution health
tail -f /var/log/prms-cron-alerts.log

# Alert on cron failures
grep "FATAL\|FAILED\|ERROR" /var/log/prms-cron-alerts.log | mail -s "PRMS Cron Alert" admin@example.com
```

**Recommended Thresholds:**
- Alert if cron hasn't run in 25+ hours
- Alert if `notifications_failed` > 0
- Alert if `recipients_found` < expected baseline

---

## Rollback Procedure

If issues are discovered:

### Option 1: Quick Rollback (Keep Database, Revert Code)

```bash
# Restore previous cron files
cp cron/overdue_alerts.php.bak cron/overdue_alerts.php
cp cron/inventory_alerts.php.bak cron/inventory_alerts.php

# Verify old version runs
php cron/overdue_alerts.php

# Keep database tables for audit trail/investigation
# (safe to keep - old cron won't use them)
```

**Disadvantage:** New tables unused, but no data loss

### Option 2: Full Rollback (Code + Database)

```bash
# 1. Restore code
cp cron/overdue_alerts.php.bak cron/overdue_alerts.php
cp cron/inventory_alerts.php.bak cron/inventory_alerts.php

# 2. Drop new tables (if confident in old implementation)
mysql -u <user> -p<pass> <db> << 'SQL'
DROP TABLE IF EXISTS cron_recipient_audit;
DROP TABLE IF EXISTS procurement_alert_recipients;
DROP TABLE IF EXISTS inventory_alert_recipients;
DROP TABLE IF EXISTS cron_execution_log;
DROP TABLE IF EXISTS cron_execution_locks;
DELETE FROM permissions WHERE name IN ('manage_cron_alert_recipients', 'view_cron_execution_logs');
SQL

# 3. Test old cron
php cron/overdue_alerts.php
```

**Note:** Before dropping tables, export audit trail for investigation:

```bash
mysqldump -u <user> -p<pass> <db> cron_recipient_audit cron_execution_log > /backup/cron_audit_$(date +%Y%m%d).sql
```

---

## Configuration Administration

### For Administrators

**Admin Interface Location:** `/admin/cron_alert_configuration.php` (optional - not included in this delivery)

**Manual SQL Configuration:**

```sql
-- View all procurement alert recipients
SELECT par.id, b.branch_name, par.recipient_type, 
       COALESCE(u.full_name, r.name) as recipient
FROM procurement_alert_recipients par
LEFT JOIN branches b ON par.branch_id = b.branch_id
LEFT JOIN users u ON u.user_id = par.recipient_user_id AND par.recipient_type = 'USER'
LEFT JOIN roles r ON r.id = par.recipient_role_id AND par.recipient_type IN ('ROLE','BRANCH_HEAD','HOD')
ORDER BY b.branch_name;

-- Add new recipient
INSERT INTO procurement_alert_recipients
  (branch_id, recipient_type, recipient_role_id, is_active, notes, created_by)
VALUES (1, 'BRANCH_HEAD', 7, 1, 'Auto-added by admin', 1);

-- Disable a recipient
UPDATE procurement_alert_recipients SET is_active = 0 WHERE id = 42;

-- View all inventory alert recipients
SELECT iar.id, COALESCE(l.location_code, 'All Locations') as location,
       iar.recipient_type, iar.alert_types,
       COALESCE(u.full_name, r.name) as recipient
FROM inventory_alert_recipients iar
LEFT JOIN inv_locations l ON iar.location_id = l.location_id
LEFT JOIN users u ON u.user_id = iar.recipient_user_id AND iar.recipient_type = 'USER'
LEFT JOIN roles r ON r.id = iar.recipient_role_id AND iar.recipient_type IN ('ROLE','PROPERTY_MANAGEMENT_OFFICER')
ORDER BY location;
```

---

## Troubleshooting

### Cron Not Running

**Symptoms:** `cron_execution_log` shows no recent entries

**Diagnosis:**
```bash
# 1. Check cron job is in crontab
crontab -l -u www-data | grep -E "overdue|inventory"

# 2. Check cron logs
grep CRON /var/log/syslog | tail -20

# 3. Run manually
php cron/overdue_alerts.php 2>&1 | head -50

# 4. Check database connection
php -r "require 'config/db.php'; echo 'Connected';" 2>&1
```

### Lock Not Released

**Symptoms:** Cron exits with "Could not acquire lock" on first run

**Diagnosis:**
```sql
-- Check for stale locks
SELECT * FROM cron_execution_locks WHERE TIMESTAMPDIFF(MINUTE, locked_at, NOW()) > 20;

-- Manually release if needed (use with caution)
DELETE FROM cron_execution_locks WHERE cron_name = 'overdue_alerts';
```

### Recipients Not Found

**Symptoms:** Cron logs "no configured recipients" despite recipients existing

**Diagnosis:**
```sql
-- Verify recipients are active and linked
SELECT COUNT(*) FROM procurement_alert_recipients WHERE branch_id = 1 AND is_active = 1;

-- Verify users are active
SELECT u.user_id, u.is_active FROM users u
WHERE u.branch_id = 1 AND u.role_id = (SELECT id FROM roles WHERE name = 'Branch Head');

-- Check permissions haven't changed
SELECT * FROM permissions WHERE name LIKE '%notification%';
```

### Duplicate Emails Still Sent

**Symptoms:** Users receiving multiple emails same day

**Diagnosis:**
```sql
-- Check reminder_log entries
SELECT request_id, user_id, reminder_type, COUNT(*) as count
FROM reminder_log
GROUP BY request_id, user_id, reminder_type
HAVING count > 1 AND DATE(sent_at) = CURDATE();

-- Check for multiple notifications per request
SELECT request_id, user_id, COUNT(*) as count
FROM user_notifications
WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
GROUP BY request_id, user_id
HAVING count > 1;
```

---

## Performance Considerations

### Expected Performance

- **Execution Time:** 5-15 seconds (for 50-100 stuck requests)
- **Database Queries:** ~20-30 per cron run
- **Lock Contention:** Negligible (unless cron interval < 5 min)

### Scaling for Large Deployments

If you have 1000+ procurement requests:

```sql
-- Add indexes for common queries
ALTER TABLE procurement_requests ADD INDEX idx_status_updated (status, updated_at);
ALTER TABLE procurement_requests ADD INDEX idx_branch_status (branch_id, status);
ALTER TABLE cron_execution_log ADD INDEX idx_cron_name_started (cron_name, started_at);
```

---

## Support & Escalation

### Reporting Issues

When reporting issues, include:
1. Execution logs from `cron_execution_log` (last 24 hours)
2. Audit trail from `cron_recipient_audit` (last 24 hours)
3. Email delivery logs from `email_notification_log`
4. Any error messages from `/var/log/prms-cron-alerts.log`

```bash
# Generate diagnostic report
mysql -u <user> -p<pass> <db> << 'SQL' > cron_diagnostic_$(date +%Y%m%d_%H%M%S).txt
SELECT 'RECENT EXECUTIONS' as section;
SELECT * FROM cron_execution_log ORDER BY started_at DESC LIMIT 10;

SELECT 'RECENT FAILURES' as section;
SELECT * FROM cron_execution_log WHERE status IN ('FAILED', 'PARTIAL_FAILURE') LIMIT 10;

SELECT 'RECIPIENTS PER EXECUTION' as section;
SELECT execution_id, COUNT(*) as recipient_count FROM cron_recipient_audit GROUP BY execution_id ORDER BY execution_id DESC LIMIT 10;

SELECT 'EMAIL DELIVERY STATUS' as section;
SELECT status, COUNT(*) as count FROM email_notification_log WHERE DATE(sent_at) >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY status;
SQL
```

---

## Conclusion

This deployment fixes the critical issues with cron alert broadcasting by:
1. ✅ Filtering procurement alerts by branch
2. ✅ Querying PMOs dynamically for inventory alerts
3. ✅ Preventing concurrent execution
4. ✅ Maintaining complete audit trail
5. ✅ Enabling configuration per organization needs

All notifications now reach **only intended recipients**, not entire user population.
