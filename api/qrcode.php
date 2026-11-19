<?php

/** @var array $config */

use Photobooth\Enum\FolderEnum;
use Photobooth\Service\SyncQueueService;
use Photobooth\Utility\QrCodeUtility;

require_once '../lib/boot.php';

if (!empty($_GET['url'])) {
    $targetUrl = trim((string) $_GET['url']);
    try {
        $result = QrCodeUtility::create($targetUrl);
        header('Content-Type: ' . $result->getMimeType());
        header('Cache-Control: public, max-age=86400');
        echo $result->getString();
    } catch (\Throwable $e) {
        http_response_code(500);
        echo 'Error generating QR Code: ' . $e->getMessage();
    }
    exit();
}

$syncService = SyncQueueService::getInstance();
$filename = (isset($_GET['filename']) && $_GET['filename'] !== '') ? (string) $_GET['filename'] : 'latest';
if ($filename === 'latest') {
    $filename = $syncService->resolveLatestSessionFilename();
}

if ($filename) {
    $filename = basename((string)$filename);
    if ($filename === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $filename)) {
        http_response_code(400);
        echo 'Invalid filename.';
        exit();
    }

    $sessionId = pathinfo($filename, PATHINFO_FILENAME);
    $imagesDir = FolderEnum::IMAGES->absolute();
    $savedQrFileName = 'qr_' . $sessionId . '.png';
    $savedQrPath = $imagesDir . DIRECTORY_SEPARATOR . $savedQrFileName;

    $wantsJson = !empty($_GET['json']) || (isset($_GET['format']) && $_GET['format'] === 'json');
    if ($wantsJson || !empty($_GET['regenerate']) || !is_file($savedQrPath)) {
        $qrPayload = $syncService->buildSessionQrData($filename, [], !empty($_GET['regenerate']));
    } else {
        $qrPayload = ['url' => $syncService->resolveQrTargetUrl($filename)];
    }

    // Return JSON metadata if requested by frontend modal
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode($qrPayload);
        exit();
    }

    if (is_file($savedQrPath)) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        readfile($savedQrPath);
        exit();
    }

    $url = (string) ($qrPayload['url'] ?? '');

    try {
        $result = QrCodeUtility::create($url);
        header('Content-Type: ' . $result->getMimeType());
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo $result->getString();
    } catch (\Exception $e) {
        http_response_code(500);
        echo 'Error generating QR Code.';
        if ($config['dev']['loglevel'] > 1) {
            echo $e->getMessage();
        }
    }
} else {
    http_response_code(400);
    echo 'No filename defined.';
}
exit();
