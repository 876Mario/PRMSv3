<?php
/**
 * Email Notification Diagnostics
 * Run this file from browser: /tools/email_diagnostic.php
 */

require_once $_SERVER['DOCUMENT_ROOT'].'/config/app.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/mailer.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/notifications.php';

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 3px solid #0b5e2b; padding-bottom: 10px; }
        h2 { color: #0b5e2b; margin-top: 30px; border-top: 1px solid #ddd; padding-top: 15px; }
        .check { margin: 15px 0; padding: 12px; border-left: 4px solid #ddd; }
        .check.pass { border-left-color: #28a745; background: #f0f8f3; }
        .check.fail { border-left-color: #dc3545; background: #fdf0f1; }
        .check.warn { border-left-color: #ffc107; background: #fffbf0; }
        .label { font-weight: bold; color: #333; }
        .value { font-family: monospace; background: #f8f9fa; padding: 8px; border-radius: 4px; word-break: break-all; }
        .status { display: inline-block; padding: 4px 12px; border-radius: 4px; font-weight: bold; font-size: 12px; }
        .status.ok { background: #28a745; color: white; }
        .status.error { background: #dc3545; color: white; }
        .status.warn { background: #ffc107; color: #333; }
        button { background: #0b5e2b; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #084a1f; }
        .test-result { margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📧 Email Notification Diagnostics</h1>
        <p>Run this diagnostic to check all email configuration and identify issues.</p>

        <h2>Configuration Check</h2>

        <?php
        // 1. Check SMTP Configuration
        $checks = [];
        
        $checks[] = [
            'name' => 'SMTP Host Configured',
            'pass' => defined('MAIL_HOST') && !empty(MAIL_HOST),
            'value' => MAIL_HOST ?? 'NOT SET'
        ];

        $checks[] = [
            'name' => 'SMTP Port Configured',
            'pass' => defined('MAIL_PORT') && !empty(MAIL_PORT),
            'value' => MAIL_PORT ?? 'NOT SET'
        ];

        $checks[] = [
            'name' => 'SMTP Username Configured',
            'pass' => defined('MAIL_USER') && !empty(MAIL_USER),
            'value' => MAIL_USER ? substr(MAIL_USER, 0, 15) . '...' : 'NOT SET'
        ];

        $checks[] = [
            'name' => 'SMTP Password Configured',
            'pass' => defined('MAIL_PASS') && !empty(MAIL_PASS),
            'value' => MAIL_PASS ? '***HIDDEN***' : 'NOT SET'
        ];

        $checks[] = [
            'name' => 'From Email Configured',
            'pass' => defined('MAIL_FROM') && !empty(MAIL_FROM),
            'value' => MAIL_FROM ?? 'NOT SET'
        ];

        foreach ($checks as $check) {
            $class = $check['pass'] ? 'pass' : 'fail';
            $status = $check['pass'] ? '✓ OK' : '✗ FAIL';
            echo "<div class='check $class'>";
            echo "<span class='label'>{$check['name']}</span> <span class='status " . ($check['pass'] ? 'ok' : 'error') . "'>$status</span><br>";
            echo "<div class='value'>{$check['value']}</div>";
            echo "</div>";
        }

        // 2. Check Database Configuration
        echo "<h2>Database Configuration</h2>";
        
        try {
            $stmt = $pdo->prepare("SELECT config_key, config_value FROM system_config WHERE config_key IN ('enable_notifications', 'petty_cash_limit', 'direct_procurement_threshold')");
            $stmt->execute();
            $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($configs as $cfg) {
                echo "<div class='check pass'>";
                echo "<span class='label'>{$cfg['config_key']}</span><br>";
                echo "<div class='value'>{$cfg['config_value']}</div>";
                echo "</div>";
            }
        } catch (Exception $e) {
            echo "<div class='check fail'><span class='label'>Database Error</span><br>";
            echo "<div class='value'>{$e->getMessage()}</div></div>";
        }

        // 3. Check Approvers Exist
        echo "<h2>Approver Users Check</h2>";
        
        try {
            $roles = ['HOD', 'Director HRM&A', 'Deputy Government Chemist'];
            foreach ($roles as $role) {
                $stmt = $pdo->prepare("
                    SELECT u.user_id, u.full_name, u.email
                    FROM users u
                    INNER JOIN roles r ON u.role_id = r.id
                    WHERE r.name = ? AND u.is_active = 1
                ");
                $stmt->execute([$role]);
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $pass = count($users) > 0;
                $class = $pass ? 'pass' : 'fail';
                $status = $pass ? '✓' : '✗';
                
                echo "<div class='check $class'>";
                echo "<span class='label'>$role Users</span> <span class='status " . ($pass ? 'ok' : 'error') . "'>$status " . count($users) . " user(s)</span><br>";
                
                foreach ($users as $user) {
                    echo "<div class='value'>{$user['full_name']} - {$user['email']}</div>";
                }
                
                if (empty($users)) {
                    echo "<div class='value' style='color: #dc3545;'>No active users found with this role</div>";
                }
                echo "</div>";
            }
        } catch (Exception $e) {
            echo "<div class='check fail'><span class='label'>Query Error</span><br>";
            echo "<div class='value'>{$e->getMessage()}</div></div>";
        }

        // 4. Check Petty Cash Limit Fix
        echo "<h2>Petty Cash Limit Verification</h2>";
        
        try {
            $stmt = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = 'petty_cash_limit'");
            $stmt->execute();
            $limit = $stmt->fetchColumn();
            
            $pass = $limit == 5000;
            $class = $pass ? 'pass' : 'warn';
            $status = $pass ? '✓ Correct' : '⚠ Needs Update';
            
            echo "<div class='check $class'>";
            echo "<span class='label'>Petty Cash Limit</span> <span class='status " . ($pass ? 'ok' : 'warn') . "'>$status</span><br>";
            echo "<div class='value'>Current: $limit JMD | Expected: 5000 JMD</div>";
            
            if (!$pass) {
                echo "<p style='color: #dc3545; margin: 10px 0;'>⚠️ Run this SQL to fix:</p>";
                echo "<div class='value'>UPDATE system_config SET config_value = '5000' WHERE config_key = 'petty_cash_limit';</div>";
            }
            echo "</div>";
        } catch (Exception $e) {
            echo "<div class='check fail'><span class='label'>Query Error</span><br>";
            echo "<div class='value'>{$e->getMessage()}</div></div>";
        }

        // 5. Test Email Sending
        echo "<h2>Test Email Send</h2>";
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_email'])) {
            $testEmail = trim($_POST['test_email']);
            
            if (filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                $testHtml = <<<HTML
<!DOCTYPE html>
<html>
<head><style>body { font-family: Arial; }</style></head>
<body>
    <h2>Test Email from PIAMS</h2>
    <p>This is a test email to verify the notification system is working correctly.</p>
    <p><strong>Timestamp:</strong> " . formatJamaicanDateTime(date('Y-m-d H:i:s'), 'd M Y, g:i:s A') . "</p>
</body>
</html>
HTML;
                
                $result = sendMail($testEmail, 'PIAMS Test Email - ' . date('H:i:s'), $testHtml);
                
                echo "<div class='test-result'>";
                if ($result) {
                    echo "<span class='status ok'>✓ EMAIL SENT SUCCESSFULLY</span><br><br>";
                    echo "Email should arrive at: <strong>$testEmail</strong><br>";
                    echo "Check your spam/junk folder if not received.";
                } else {
                    echo "<span class='status error'>✗ EMAIL SEND FAILED</span><br><br>";
                    echo "Check PHP error logs for details: <br>";
                    echo "<code>tail -50 /path/to/php-error.log | grep MAIL</code>";
                }
                echo "</div>";
            } else {
                echo "<div class='test-result'>";
                echo "<span class='status error'>Invalid email address</span>";
                echo "</div>";
            }
        }
        ?>

        <form method="POST" style="margin-top: 20px;">
            <label for="test_email">Test Email Address:</label><br><br>
            <input type="email" id="test_email" name="test_email" required placeholder="Enter email to test" style="padding: 8px; width: 300px;">
            <button type="submit">Send Test Email</button>
        </form>

        <h2>Error Logs</h2>
        <p>Review PHP error logs for detailed diagnostic information:</p>
        <code>tail -100 /path/to/php-error.log | grep -i "NOTIFY\|MAIL\|notification"</code>

        <h2>Recent Notifications Test</h2>
        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_request_id'])) {
            $requestId = (int)$_POST['test_request_id'];
            
            if ($requestId > 0) {
                try {
                    $stmt = $pdo->prepare("SELECT request_id FROM procurement_requests WHERE request_id = ?");
                    $stmt->execute([$requestId]);
                    $exists = $stmt->fetch();
                    
                    if ($exists) {
                        echo "<div class='test-result'>";
                        echo "<p>Testing notification for Request ID: $requestId</p>";
                        
                        // Test the notification function
                        $stmt2 = $pdo->prepare("
                            SELECT ra.role FROM request_approvals ra
                            WHERE ra.request_id = ? AND ra.status = 'pending'
                            ORDER BY ra.stage_order ASC LIMIT 1
                        ");
                        $stmt2->execute([$requestId]);
                        $approval = $stmt2->fetch();
                        
                        if ($approval) {
                            echo "<p>First approver role: <strong>{$approval['role']}</strong></p>";
                            
                            // Find user with that role
                            $stmt3 = $pdo->prepare("
                                SELECT u.user_id, u.email FROM users u
                                INNER JOIN roles r ON u.role_id = r.id
                                WHERE r.name = ? AND u.is_active = 1 LIMIT 1
                            ");
                            $stmt3->execute([$approval['role']]);
                            $user = $stmt3->fetch();
                            
                            if ($user) {
                                echo "<p>Approver: <strong>{$user['email']}</strong></p>";
                                echo "<p><button onclick=\"if(confirm('Send test email to " . htmlspecialchars($user['email']) . "?')) { location.href='?send_test=1&req=" . $requestId . "&user=" . $user['user_id'] . "'; }\">Send Test Notification</button></p>";
                            } else {
                                echo "<p style='color: #dc3545;'>No user found with role: {$approval['role']}</p>";
                            }
                        } else {
                            echo "<p style='color: #dc3545;'>No pending approvals found for this request</p>";
                        }
                        echo "</div>";
                    } else {
                        echo "<div class='check fail'><p>Request ID $requestId not found</p></div>";
                    }
                } catch (Exception $e) {
                    echo "<div class='check fail'><p>{$e->getMessage()}</p></div>";
                }
            }
        }
        ?>

        <form method="POST" style="margin-top: 15px;">
            <label for="test_request_id">Test Notification for Request ID:</label><br><br>
            <input type="number" id="test_request_id" name="test_request_id" placeholder="Enter request ID" style="padding: 8px; width: 200px;">
            <button type="submit">Test Notification</button>
        </form>
    </div>
</body>
</html>
