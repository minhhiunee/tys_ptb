<?php

declare(strict_types=1);

require_once '../lib/boot.php';

use Photobooth\Service\SyncQueueService;

header('Content-Type: application/json');

$syncService = SyncQueueService::getInstance();
$action = $_REQUEST['action'] ?? 'summary';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrfOrFail($_POST);

    if ($action === 'sync_session' && !empty($_POST['session_id'])) {
        $sessionId = trim((string) $_POST['session_id']);
        echo json_encode($syncService->syncSession($sessionId));
        exit();
    }

    if ($action === 'process_queue') {
        $batch = isset($_POST['batch']) ? max(1, min(50, (int) $_POST['batch'])) : 10;
        echo json_encode($syncService->processPendingQueue($batch));
        exit();
    }
}

echo json_encode($syncService->getQueueSummary());
exit();

