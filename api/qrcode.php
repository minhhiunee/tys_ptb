<?php

/** @var array $config */

use Photobooth\Service\RemoteStorageService;
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

    // Determine target URL for QR Code
    $customCloudUrl = trim((string) ($config['qr']['custom_url'] ?? $config['qr']['cloud_url'] ?? ''));
    if ($customCloudUrl !== '') {
        $url = rtrim($customCloudUrl, '/') . '/view.php?image=' . $filename;
    } elseif ($config['ftp']['enabled'] && $config['ftp']['useForQr']) {
        $remoteStorageService = RemoteStorageService::getInstance();
        $url = $remoteStorageService->getWebpageUri();
        if ($config['qr']['append_filename']) {
            $url .= '/images/';
        }
        if ($config['qr']['append_filename']) {
            $url .= $filename;
        }
        $url = PathUtility::getPublicPath($url, true);
    } else {
        $url = $config['qr']['url'] ?? 'view.php?image=';
        if ($config['qr']['append_filename'] ?? true) {
            $url .= $filename;
        }
        $url = PathUtility::getPublicPath($url, true);

        // Smart Local Host IP Resolution: If URL points to localhost/127.0.0.1, resolve real local LAN IP
        if (preg_match('#://(localhost|127\.0\.0\.1)(:\d+)?#', $url)) {
            $localIp = null;
            if (!empty($config['qr']['local_ip'])) {
                $localIp = $config['qr']['local_ip'];
            } elseif (!empty($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== 'localhost' && !str_starts_with($_SERVER['HTTP_HOST'], '127.0.0.1')) {
                $localIp = explode(':', $_SERVER['HTTP_HOST'])[0];
            } else {
                $hostName = gethostname();
                $resolved = @gethostbyname($hostName);
                if ($resolved && $resolved !== '127.0.0.1' && $resolved !== '127.0.1.1') {
                    $localIp = $resolved;
                }
            }

            if ($localIp) {
                $url = preg_replace('#://(localhost|127\.0\.0\.1)#', '://' . $localIp, $url);
            }
        }
    }

    // Return JSON metadata if requested by frontend modal
    if (!empty($_GET['json']) || (isset($_GET['format']) && $_GET['format'] === 'json')) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'url' => $url,
            'qr_image' => PathUtility::getPublicPath('api/qrcode.php?filename=' . rawurlencode($filename)),
            'filename' => $filename,
            'is_cloud' => ($customCloudUrl !== ''),
            'is_local' => !str_starts_with($url, 'https://') && !empty($localIp),
        ]);
        exit();
    }

    try {
        $result = QrCodeUtility::create($url);
        header('Content-Type: ' . $result->getMimeType());
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
