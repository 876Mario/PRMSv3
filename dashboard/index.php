<?php
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/page_guard.php";

/*
|--------------------------------------------------------------------------
| SOP-Aligned Dashboard Router
|--------------------------------------------------------------------------
*/


$role = $_SESSION['role_name'] ?? '';

switch ($role) {
    // System Control
    case 'SuperAdmin':
    case 'Admin':
        header("Location: /dashboard/admin.php"); exit;

    // Final Authority
    case 'Deputy Government Chemist':
        header("Location: /dashboard/gc.php"); exit;

    // Recommendation Authority
    case 'Procurement Committee':
        header("Location: /dashboard/committee.php"); exit;

    // Operational Roles
    case 'Procurement Officer':
        header("Location: /dashboard/procurement.php"); exit;
    case 'Evaluation Committee Member':
        header("Location: /dashboard/evaluation.php"); exit;
    case 'Finance Officer':
        header("Location: /dashboard/finance.php"); exit;
    case 'HOD':
        header("Location: /dashboard/hod.php"); exit;

    // New: Director HRM&A
    case 'Director HRM&A':
        header("Location: /dashboard/director_hrma.php"); exit;

    // New: Director Procurement
    case 'Director Procurement':
        header("Location: /dashboard/director_procurement.php"); exit;

    // New: Director Accounts & Finance
    case 'Director Accounts & Finance':
        header("Location: /dashboard/director_accounts_finance.php"); exit;

    // New: Property Management Officer
    case 'Property Management Officer':
        header("Location: /dashboard/property_management_officer.php"); exit;

    // New: Requestor
    case 'Requestor':
        header("Location: /dashboard/requestor.php"); exit;

    // Read Only
    case 'Viewer':
    default:
        header("Location: /dashboard/viewer.php"); exit;
}
