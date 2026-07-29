<?php
$REQUIRE_PERMISSION = 'manage_users';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/config/helper.php';

$user_id      = $_POST['user_id']      ?? null;
$job_title_id = $_POST['job_title_id'] ?? null;

if (!ctype_digit((string)$user_id)) {
    pop("Invalid request.", "/users/list.php", 1500, "error");
    exit;
}

$user_id = (int)$user_id;

/* Allow clearing (empty string → null) */
if ($job_title_id === '' || $job_title_id === null) {
    $job_title_id = null;
} else {
    if (!ctype_digit((string)$job_title_id)) {
        pop("Invalid job title.", "/users/view.php?id={$user_id}", 1500, "error");
        exit;
    }
    $job_title_id = (int)$job_title_id;

    /* Validate job title exists */
    $chk = $pdo->prepare("SELECT title_name FROM job_titles WHERE id = ? AND is_active = 1");
    $chk->execute([$job_title_id]);
    $jtRow = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$jtRow) {
        pop("Selected job title does not exist.", "/users/view.php?id={$user_id}", 1500, "error");
        exit;
    }
    $jtName = $jtRow['title_name'];
}

/* Update */
$pdo->prepare("UPDATE users SET job_title_id = ? WHERE user_id = ?")
    ->execute([$job_title_id, $user_id]);

logAudit(
    $pdo,
    'users',
    $user_id,
    'JOB_TITLE_CHANGE',
    $job_title_id
        ? "Job title updated to '{$jtName}'"
        : "Job title cleared"
);

pop(
    $job_title_id ? "Job title updated successfully." : "Job title cleared.",
    "/users/view.php?id={$user_id}",
    1200,
    "success"
);
exit;
