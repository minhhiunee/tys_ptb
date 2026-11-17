<?php

declare(strict_types=1);

require_once '../lib/boot.php';

use Photobooth\Service\SyncQueueService;
use Photobooth\Utility\PathUtility;

header('Content-Type: application/json');

$syncService = SyncQueueService::getInstance();
$action = $_REQUEST['action'] ?? 'summary';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrfOrFail($_POST);

    if ($action === 'sync_session' && !empty($_POST['session_id'])) {
        $sessionId = trim((string) $_POST['session_id']);
        $record = $syncService->getSessionRecord($sessionId);

        // If session is already confirmed synced on Cloud, return immediately
        if ($record && ($record['status'] ?? '') === 'synced') {
            echo json_encode(['success' => true, 'already_synced' => true, 'record' => $record]);
            exit();
        }

        // Trigger background CLI runner outside of PHP-FPM so web requests never hang or block the pool
        $runnerScript = PathUtility::getAbsolutePath('api/backgroundSyncRunner.php');
        $cmd = sprintf('php %s %s > /dev/null 2>&1 &', escapeshellarg($runnerScript), escapeshellarg($sessionId));
        @exec($cmd);

        echo json_encode([
            'success' => true,
            'mode' => 'async_dispatched',
            'record' => $record ?? ['session_id' => $sessionId, 'status' => 'pending']
        ]);
        exit();
    }

    if ($action === 'process_queue') {
        $batch = isset($_POST['batch']) ? max(1, min(50, (int) $_POST['batch'])) : 10;
        echo json_encode($syncService->processPendingQueue($batch));
        exit();
    }
}

if ($action === 'get_session' && !empty($_GET['session_id'])) {
    $sessionId = trim((string) $_GET['session_id']);
    $record = $syncService->getSessionRecord($sessionId);
    echo json_encode(['success' => true, 'record' => $record]);
    exit();
}

echo json_encode($syncService->getQueueSummary());
exit();
