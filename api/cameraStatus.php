<?php

declare(strict_types=1);

require_once '../lib/boot.php';

use Photobooth\Service\CameraManagerService;

header('Content-Type: application/json');

$cameraManager = CameraManagerService::getInstance();
$action = $_REQUEST['action'] ?? 'status';
$providerId = isset($_REQUEST['provider']) ? trim((string) $_REQUEST['provider']) : null;

if ($action === 'reconnect') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        checkCsrfOrFail($_POST);
    }
    $provider = $cameraManager->getActiveProvider($providerId ? ['provider' => $providerId] : []);
    $reconnected = $provider->reconnect();
    echo json_encode([
        'success' => $reconnected,
        'provider' => $provider->getId(),
        'status' => $provider->getStatus(),
    ]);
    exit();
}

echo json_encode($cameraManager->getSystemCameraStatus($providerId));
exit();

