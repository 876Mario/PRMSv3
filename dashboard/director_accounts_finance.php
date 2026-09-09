<?php
$REQUIRE_PERMISSION = 'view_director_dashboard';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/page_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/pagination.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/DashboardActionService.php';

$dashboardModel = DashboardActionService::buildDashboard($pdo, $_SESSION, 'director_accounts_finance');

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
require $_SERVER['DOCUMENT_ROOT'] . '/dashboard/widgets/action_dashboard.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
