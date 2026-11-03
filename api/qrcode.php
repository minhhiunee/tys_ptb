<?php

/** @var array $config */

use Photobooth\Service\RemoteStorageService;
use Photobooth\Service\SyncQueueService;
use Photobooth\Utility\PathUtility;
use Photobooth\Utility\QrCodeUtility;

require_once '../lib/boot.php';

$filename = (isset($_GET['filename']) && $_GET['filename']) != '' ? $_GET['filename'] : false;
if ($filename) {
    $filename = basename((string)$filename);
    if ($filename === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $filename)) {
        http_response_code(400);
        echo 'Invalid filename.';
        exit();
    }

    // Load active Network Mode configuration (Hướng 1: venue_wifi vs Hướng 2: cloud_web)
    $netModeFile = PathUtility::getAbsolutePath('config/network_mode.json');
    $netConfig = [
        'mode' => 'venue_wifi',
        'venue_ip' => trim((string) ($config['qr']['local_ip'] ?? '')),
        'venue_ssid' => '',
        'cloud_url' => trim((string) ($config['qr']['custom_url'] ?? $config['qr']['cloud_url'] ?? '')),
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

    if ($activeMode === 'cloud_web' && $customCloudUrl !== '') {
        // HƯỚNG 2: Đẩy lên Website riêng trên Cloud (4G / Any Wi-Fi)
        $baseCloud = rtrim($customCloudUrl, '/');
        if (str_ends_with($baseCloud, '/view.php')) {
            $url = $baseCloud . '?image=' . rawurlencode($filename);
        } else {
            $url = $baseCloud . '/view.php?image=' . rawurlencode($filename);
        }

        // Check or trigger sync for this session when JSON metadata is requested
        if (!empty($_GET['json'])) {
            $sessionId = pathinfo($filename, PATHINFO_FILENAME);
            $syncService = SyncQueueService::getInstance();
            $rec = $syncService->getSessionRecord($sessionId);
            if (!$rec) {
                $rec = $syncService->enqueueSession($sessionId, ['final_photo' => $filename]);
            }
            if (($rec['status'] ?? '') !== 'synced') {
                $syncRes = $syncService->syncSession($sessionId);
                $rec = $syncRes['record'] ?? $rec;
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

    // Return JSON metadata if requested by frontend modal
    if (!empty($_GET['json']) || (isset($_GET['format']) && $_GET['format'] === 'json')) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'mode' => $activeMode,
            'url' => $url,
            'qr_image' => PathUtility::getPublicPath('api/qrcode.php?filename=' . rawurlencode($filename) . '&mode=' . $activeMode . '&v=' . ($netConfig['updated_at'] ?? time())),
            'filename' => $filename,
            'is_cloud' => ($activeMode === 'cloud_web'),
            'is_local' => ($activeMode === 'venue_wifi'),
            'venue_ip' => $localIp,
            'venue_ssid' => $venueSsid,
            'cloud_url' => $customCloudUrl,
            'sync_status' => $syncStatus,
        ]);
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
