<?php

declare(strict_types=1);

require_once '../lib/boot.php';

use Photobooth\Service\CaptureOneIntegrationService;

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$c1Service = CaptureOneIntegrationService::getInstance();
$action = $_REQUEST['action'] ?? 'status';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrfOrFail($_POST);

    if ($action === 'self_test') {
        $testResult = $c1Service->runDiagnosticSelfTest();
        echo json_encode([
            'success' => !empty($testResult['passed']),
            'self_test' => $testResult,
            'diagnostics' => $c1Service->getDiagnostics(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit();
    }

    if ($action === 'scan_now') {
        $scanResult = $c1Service->scanAndIngestOutputFolder();
        echo json_encode([
            'success' => true,
            'scan' => $scanResult,
            'diagnostics' => $c1Service->getDiagnostics(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit();
    }
}

echo json_encode([
    'success' => true,
    'diagnostics' => $c1Service->getDiagnostics(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit();

