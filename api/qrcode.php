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

    // Load active Cloud R2 / Venue Wi-Fi configuration via SyncQueueService
    $syncService = SyncQueueService::getInstance();
    $netConfig = $syncService->getNetworkModeConfig();
    $activeMode = ($netConfig['mode'] === 'cloud_web' && !empty($netConfig['cloud_url'])) ? 'cloud_web' : 'venue_wifi';
    $customCloudUrl = trim((string) ($netConfig['cloud_url'] ?? ''));
    $localIp = trim((string) ($netConfig['venue_ip'] ?? ''));
    $venueSsid = trim((string) ($netConfig['venue_ssid'] ?? ''));
    $syncStatus = null;

    $sessionId = pathinfo($filename, PATHINFO_FILENAME);
    $imagesDir = FolderEnum::IMAGES->absolute();
    $metaFilePath = $imagesDir . DIRECTORY_SEPARATOR . 'meta_' . $filename . '.json';
    $savedQrFileName = 'qr_' . $sessionId . '.png';
    $savedQrPath = $imagesDir . DIRECTORY_SEPARATOR . $savedQrFileName;

    $sessionMeta = [];
    if (is_file($metaFilePath)) {
        $decodedMeta = json_decode((string) @file_get_contents($metaFilePath), true);
        if (is_array($decodedMeta)) {
            $sessionMeta = $decodedMeta;
        }
    }

    $url = $syncService->resolveQrTargetUrl($filename);

    if ($activeMode === 'cloud_web' && $customCloudUrl !== '' && !empty($_GET['json'])) {
        $rec = $syncService->getSessionRecord($sessionId);
        if (!$rec) {
            $rec = $syncService->enqueueSession($sessionId, ['final_photo' => $filename], false);
        }
        $syncStatus = $rec['status'] ?? 'pending';
    }

    // Ensure QR image is generated and persisted alongside the photo in data/images/qr_<sessionId>.png
    // so viewing history in Gallery always has the pre-generated QR code ready on disk
    $needGenerateQrFile = !is_file($savedQrPath)
        || (($sessionMeta['qr_url'] ?? '') !== $url)
        || !empty($_GET['regenerate']);

    if ($needGenerateQrFile) {
        try {
            $qrResult = QrCodeUtility::create($url);
            @file_put_contents($savedQrPath, $qrResult->getString());
            $sessionMeta['session_id'] = $sessionMeta['session_id'] ?? $sessionId;
            $sessionMeta['final_photo'] = $sessionMeta['final_photo'] ?? $filename;
            $sessionMeta['qr_url'] = $url;
            $sessionMeta['qr_file'] = $savedQrFileName;
            @file_put_contents(
                $metaFilePath,
                json_encode($sessionMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        } catch (\Throwable) {
            // fallback to on-the-fly rendering below
        }
    }

    $qrPublicUrl = is_file($savedQrPath)
        ? PathUtility::getPublicPath('data/images/' . $savedQrFileName) . '?v=' . (int) @filemtime($savedQrPath)
        : PathUtility::getPublicPath('api/qrcode.php?filename=' . rawurlencode($filename) . '&mode=' . $activeMode . '&v=' . ($netConfig['updated_at'] ?? time()));

    // Return JSON metadata if requested by frontend modal
    if (!empty($_GET['json']) || (isset($_GET['format']) && $_GET['format'] === 'json')) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'mode' => $activeMode,
            'url' => $url,
            'qr_image' => $qrPublicUrl,
            'qr_file' => is_file($savedQrPath) ? $savedQrFileName : null,
            'filename' => $filename,
            'session_id' => $sessionId,
            'is_cloud' => ($activeMode === 'cloud_web'),
            'is_local' => ($activeMode === 'venue_wifi'),
            'venue_ip' => $localIp,
            'venue_ssid' => $venueSsid,
            'cloud_url' => $customCloudUrl,
            'sync_status' => $syncStatus,
        ]);
        exit();
    }

    if (is_file($savedQrPath)) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        readfile($savedQrPath);
        exit();
    }

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
