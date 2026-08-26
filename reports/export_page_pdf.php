<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if (!has_permission('view_financial_reports')) {
    http_response_code(403);
    exit('Forbidden');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

if (
    empty($_POST['csrf_token'])
    || empty($_SESSION['prms_export_csrf_token'])
    || !hash_equals($_SESSION['prms_export_csrf_token'], (string)$_POST['csrf_token'])
) {
    http_response_code(403);
    exit('Invalid export token');
}

$title = trim((string)($_POST['title'] ?? 'Export'));
$html = (string)($_POST['html'] ?? '');

if ($html === '' || strlen($html) > 2000000) {
    http_response_code(400);
    exit('Invalid export content');
}

$safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$filename = preg_replace('/[^a-z0-9]+/i', '_', strtolower($title));
$filename = trim($filename, '_') ?: 'export';

$html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
$html = preg_replace('/\s+on[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', $html);
$html = preg_replace('#<(button|form)\b[^>]*>.*?</\1>#is', '', $html);

$document = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #222; }
        h1, h2, h3, h4 { margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { border: 1px solid #ccc; padding: 5px; vertical-align: top; }
        th { background: #f2f2f2; font-weight: bold; }
        .btn, button, form, nav, .prms-export-toolbar { display: none !important; }
        .card { border: 1px solid #ddd; margin-bottom: 12px; padding: 8px; }
        a { color: #222; text-decoration: none; }
    </style>
</head>
<body>
    <h2>{$safeTitle}</h2>
    {$html}
</body>
</html>
HTML;

$options = new Options();
$options->set('isRemoteEnabled', false);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($document);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream($filename . '.pdf', ['Attachment' => true]);
