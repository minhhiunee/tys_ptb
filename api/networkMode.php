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
function loadNetworkModeConfig(string $modeFile, array $appConfig): array
{
    $defaults = [
        'mode' => 'venue_wifi', // 'venue_wifi' (Hướng 1) or 'cloud_web' (Hướng 2)
        'venue_ip' => (string) ($appConfig['qr']['local_ip'] ?? ''),
        'venue_ssid' => '',
        'cloud_url' => (string) ($appConfig['qr']['custom_url'] ?? ''),
        'cloud_api_key' => (string) ($appConfig['sync']['api_key'] ?? 'tys_secret_key_2026'),
        'auto_compress_cloud' => true,
        'updated_at' => time(),
    ];

    if (file_exists($modeFile)) {
        $raw = @file_get_contents($modeFile);
        $decoded = $raw ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $defaults = array_merge($defaults, $decoded);
        }
    }

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

$currentConfig = loadNetworkModeConfig($modeFile, $config);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrfOrFail($_POST);

    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        $newMode = ($_POST['mode'] ?? 'venue_wifi') === 'cloud_web' ? 'cloud_web' : 'venue_wifi';
        $venueIp = trim((string) ($_POST['venue_ip'] ?? $currentConfig['venue_ip']));
        // Strip http:// or trailing slashes if user pasted full URL into IP field
        $venueIp = preg_replace('#^https?://#i', '', $venueIp) ?? $venueIp;
        $venueIp = rtrim($venueIp, '/');

        $venueSsid = trim((string) ($_POST['venue_ssid'] ?? $currentConfig['venue_ssid']));
        $cloudUrl = rtrim(trim((string) ($_POST['cloud_url'] ?? $currentConfig['cloud_url'])), '/');
        $cloudApiKey = trim((string) ($_POST['cloud_api_key'] ?? $currentConfig['cloud_api_key']));
        $autoCompress = isset($_POST['auto_compress_cloud']) ? (bool) $_POST['auto_compress_cloud'] : true;

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

echo json_encode([
    'success' => true,
    'config' => $currentConfig,
    'sync_summary' => SyncQueueService::getInstance()->getQueueSummary(),
]);
exit();

