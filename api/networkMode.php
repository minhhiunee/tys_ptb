<?php

declare(strict_types=1);

require_once '../lib/boot.php';

use Photobooth\Service\SyncQueueService;
use Photobooth\Utility\PathUtility;

header('Content-Type: application/json');

$modeFile = PathUtility::getAbsolutePath('config/network_mode.json');

/**
 * Load current network delivery mode configuration
 */
function loadNetworkModeConfig(): array
{
    $defaults = SyncQueueService::getInstance()->getNetworkModeConfig();

    // Auto-detect non-Docker LAN IP if venue_ip is still empty
    if (empty($defaults['venue_ip'])) {
        $hostHeader = $_SERVER['HTTP_HOST'] ?? '';
        $hostIp = explode(':', $hostHeader)[0];
        if (
            $hostIp !== '' &&
            $hostIp !== 'localhost' &&
            !str_starts_with($hostIp, '127.') &&
            !preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $hostIp)
        ) {
            $defaults['venue_ip'] = $hostIp;
        }
    }

    return $defaults;
}

$currentConfig = loadNetworkModeConfig();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrfOrFail($_POST);

    $action = $_POST['action'] ?? 'save';

    if ($action === 'test_cloud') {
        $testUrl = SyncQueueService::normalizeCloudBaseUrl((string) ($_POST['cloud_url'] ?? $currentConfig['cloud_url']));
        if ($testUrl === '') {
            echo json_encode(['success' => false, 'error' => 'Vui lòng nhập URL Cloudflare Worker / Web riêng.']);
            exit();
        }
        $healthUrl = $testUrl . '/api/health';
        $ch = curl_init($healthUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp !== false && $code >= 200 && $code < 400) {
            $decoded = json_decode((string) $resp, true);
            $r2Bound = is_array($decoded) ? !empty($decoded['r2_bound']) : null;
            echo json_encode([
                'success' => true,
                'http_code' => $code,
                'r2_bound' => $r2Bound,
                'message' => $r2Bound === true
                    ? 'Kết nối Cloudflare Worker & R2 Bucket thành công ✓'
                    : ($r2Bound === false ? 'Đã kết nối Worker nhưng chưa gắn R2_BUCKET!' : 'Kết nối máy chủ Cloud thành công ✓'),
            ]);
            exit();
        }

        echo json_encode([
            'success' => false,
            'error' => $err !== '' ? $err : "Máy chủ trả về HTTP {$code}",
        ]);
        exit();
    }

    if ($action === 'save') {
        $newMode = ($_POST['mode'] ?? 'cloud_web') === 'venue_wifi' ? 'venue_wifi' : 'cloud_web';
        $venueIp = trim((string) ($_POST['venue_ip'] ?? $currentConfig['venue_ip']));
        // Strip http:// or trailing slashes if user pasted full URL into IP field
        $venueIp = preg_replace('#^https?://#i', '', $venueIp) ?? $venueIp;
        $venueIp = rtrim($venueIp, '/');

        $venueSsid = trim((string) ($_POST['venue_ssid'] ?? $currentConfig['venue_ssid']));
        $cloudUrl = rtrim(trim((string) ($_POST['cloud_url'] ?? $currentConfig['cloud_url'])), '/');
        $cloudApiKey = trim((string) ($_POST['cloud_api_key'] ?? $currentConfig['cloud_api_key']));
        $autoCompress = isset($_POST['auto_compress_cloud'])
            ? filter_var($_POST['auto_compress_cloud'], FILTER_VALIDATE_BOOLEAN)
            : (bool) ($currentConfig['auto_compress_cloud'] ?? false);

        $updated = [
            'mode' => $newMode,
            'venue_ip' => $venueIp,
            'venue_ssid' => $venueSsid,
            'cloud_url' => $cloudUrl,
            'cloud_api_key' => $cloudApiKey,
            'auto_compress_cloud' => $autoCompress,
            'updated_at' => time(),
        ];

        file_put_contents(
            $modeFile,
            json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        echo json_encode([
            'success' => true,
            'config' => $updated,
            'sync_summary' => SyncQueueService::getInstance()->getQueueSummary(),
        ]);
        exit();
    }
}

$syncService = SyncQueueService::getInstance();
$targetFile = (isset($_GET['file']) && $_GET['file'] !== '') ? (string) $_GET['file'] : 'latest';
if ($targetFile === 'latest') {
    $targetFile = $syncService->resolveLatestSessionFilename();
}

$qrData = $syncService->buildSessionQrData($targetFile);

echo json_encode(array_merge([
    'success' => true,
    'config' => $currentConfig,
    'sync_summary' => $syncService->getQueueSummary(),
], $qrData));
exit();

