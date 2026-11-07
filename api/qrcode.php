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

    // Load active Cloud R2 configuration (Mặc định cố định dùng Cloudflare R2)
    $netModeFile = PathUtility::getAbsolutePath('config/network_mode.json');
    $netConfig = [
        'mode' => 'cloud_web',
        'venue_ip' => trim((string) ($config['qr']['local_ip'] ?? '')),
        'venue_ssid' => '',
        'cloud_url' => trim((string) ($config['qr']['custom_url'] ?? $config['qr']['cloud_url'] ?? 'https://tysptb.kocodau2006.workers.dev')),
    ];
    if (file_exists($netModeFile)) {
        $rawNet = @file_get_contents($netModeFile);
        $decodedNet = $rawNet ? json_decode($rawNet, true) : null;
        if (is_array($decodedNet)) {
            $netConfig = array_merge($netConfig, $decodedNet);
        }
    }

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

    if ($activeMode === 'cloud_web' && $customCloudUrl !== '') {
        // HƯỚNG 2: Đẩy lên Website riêng trên Cloud R2 / Worker (4G / Any Wi-Fi)
        $baseCloud = preg_replace('/\?.*$/', '', trim($customCloudUrl)) ?? trim($customCloudUrl);
        $baseCloud = rtrim($baseCloud, '/');
        if (str_ends_with($baseCloud, '/view.php')) {
            $url = $baseCloud . '?s=' . rawurlencode($sessionId) . '&image=' . rawurlencode($filename);
        } else {
            $url = $baseCloud . '/?s=' . rawurlencode($sessionId);
        }

        // Check session sync state without blocking QR code generation
        if (!empty($_GET['json'])) {
            $syncService = SyncQueueService::getInstance();
            $rec = $syncService->getSessionRecord($sessionId);
            if (!$rec) {
                $rec = $syncService->enqueueSession($sessionId, ['final_photo' => $filename], false);
            }
            $syncStatus = $rec['status'] ?? 'pending';
        }
    } elseif ($config['ftp']['enabled'] && $config['ftp']['useForQr']) {
        $remoteStorageService = RemoteStorageService::getInstance();
        $url = $remoteStorageService->getWebpageUri();
        if ($config['qr']['append_filename']) {
            $url .= '/images/' . $filename;
        }
        $url = PathUtility::getPublicPath($url, true);
    } else {
        // HƯỚNG 1: Mạng Wi-Fi tại địa điểm (Local Venue Wi-Fi)
        $url = $config['qr']['url'] ?? 'view.php?image=';
        if ($config['qr']['append_filename'] ?? true) {
            $url .= rawurlencode($filename);
        }
        $url = PathUtility::getPublicPath($url, true);

        // Smart Local Host IP Resolution (avoids Docker internal 172.x.x.x IP)
        if (preg_match('#://(localhost|127\.0\.0\.1|172\.(1[6-9]|2[0-9]|3[0-1])\.\d+\.\d+)(:\d+)?#', $url)) {
            if ($localIp === '') {
                $hostHeader = $_SERVER['HTTP_HOST'] ?? '';
                $candidateHost = explode(':', $hostHeader)[0];
                if (
                    $candidateHost !== '' &&
                    $candidateHost !== 'localhost' &&
                    !str_starts_with($candidateHost, '127.') &&
                    !preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $candidateHost)
                ) {
                    $localIp = $candidateHost;
                }
            }

            if ($localIp !== '') {
                $url = preg_replace(
                    '#://(localhost|127\.0\.0\.1|172\.(1[6-9]|2[0-9]|3[0-1])\.\d+\.\d+)#',
                    '://' . $localIp,
                    $url
                );
            }
        }
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
