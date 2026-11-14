<?php

/** @var array $config */

use Photobooth\Enum\FolderEnum;
use Photobooth\Service\RemoteStorageService;
use Photobooth\Service\SyncQueueService;
use Photobooth\Utility\PathUtility;
use Photobooth\Utility\QrCodeUtility;

require_once '../lib/boot.php';

$filename = (isset($_GET['filename']) && $_GET['filename'] !== '') ? (string) $_GET['filename'] : 'latest';
if ($filename === 'latest') {
    $imagesDir = FolderEnum::IMAGES->absolute();
    $latestFile = '';
    $latestMtime = 0;
    if (is_dir($imagesDir)) {
        $entries = @scandir($imagesDir) ?: [];
        foreach ($entries as $entry) {
            if (
                !str_ends_with(strtolower($entry), '.jpg') ||
                str_starts_with($entry, 'orig_') ||
                str_starts_with($entry, 'tl_') ||
                str_starts_with($entry, 'preview_') ||
                preg_match('/-\d+\.jpg$/i', $entry)
            ) {
                continue;
            }
            $fullPath = $imagesDir . DIRECTORY_SEPARATOR . $entry;
            $mtime = (int) @filemtime($fullPath);
            if ($mtime >= $latestMtime) {
                $latestMtime = $mtime;
                $latestFile = $entry;
            }
        }
    }
    $filename = $latestFile !== '' ? $latestFile : 'demo_session.jpg';
}

if ($filename) {
    $filename = basename((string)$filename);
    if ($filename === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $filename)) {
        http_response_code(400);
        echo 'Invalid filename.';
        exit();
    }

    $syncService = SyncQueueService::getInstance();
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
