<?php
$REQUIRE_PERMISSION = 'view_own_requests';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pagination.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/DashboardActionService.php';

$dashboardModel = DashboardActionService::buildDashboard($pdo, $_SESSION, 'requestor');

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
require $_SERVER['DOCUMENT_ROOT'] . '/dashboard/widgets/action_dashboard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
