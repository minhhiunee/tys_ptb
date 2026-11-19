<?php

declare(strict_types=1);

namespace Photobooth\Service;

use Photobooth\Enum\FolderEnum;
use Photobooth\Utility\PathUtility;
use Photobooth\Utility\QrCodeUtility;
use Psr\Log\LoggerInterface;

class SyncQueueService
{
    public const DEFAULT_CLOUD_URL = 'https://tysptb.kocodau2006.workers.dev';
    public const DEFAULT_API_KEY = 'tys_secret_key_2026';

    private static ?self $instance = null;
    protected LoggerInterface $logger;
    protected string $queueFile;
    protected array $config;

    private function __construct()
    {
        $this->logger = LoggerService::getInstance()->getLogger('main');
        $this->config = ConfigurationService::getInstance()->getConfiguration();
        $this->queueFile = FolderEnum::DATA->absolute() . DIRECTORY_SEPARATOR . 'sync_queue.json';
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get current environment profile ('local', 'staging', 'production')
     */
    public function getEnvironmentProfile(): string
    {
        $env = strtolower((string) ($this->config['sync']['environment'] ?? getenv('PHOTOBOOTH_ENV') ?: 'local'));
        return in_array($env, ['local', 'staging', 'production'], true) ? $env : 'local';
    }

    /**
     * Enqueue or update a capture session for cloud synchronization.
     * Prevents duplicate records for the same session_id.
     */
    public function enqueueSession(string $sessionId, array $sessionData, bool $immediateSync = false): array
    {
        $queue = $this->loadQueue();
        $now = time();

        $existing = $queue[$sessionId] ?? null;
        $gifPhoto = $sessionData['gif_photo'] ?? ($existing['gif_photo'] ?? null);
        if (empty($gifPhoto)) {
            $candidateGif = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . $sessionId . '.gif';
            if (is_file($candidateGif)) {
                $gifPhoto = $sessionId . '.gif';
            }
        }

        $record = [
            'session_id' => $sessionId,
            'environment' => $this->getEnvironmentProfile(),
            'final_photo' => $sessionData['final_photo'] ?? ($existing['final_photo'] ?? ($sessionId . '.jpg')),
            'gif_photo' => $gifPhoto,
            'original_photos' => $sessionData['original_photos'] ?? ($existing['original_photos'] ?? []),
            'metadata' => array_merge($existing['metadata'] ?? [], $sessionData['metadata'] ?? []),
            'status' => 'pending', // Reset to pending when created or updated (e.g. preset changed)
            'attempts' => $existing ? (int) ($existing['attempts'] ?? 0) : 0,
            'last_error' => null,
            'created_at' => $existing['created_at'] ?? $now,
            'updated_at' => $now,
            'synced_at' => null,
        ];

        $queue[$sessionId] = $record;
        $this->saveQueue($queue);

        $this->logger->debug("Session queued for sync: {$sessionId}");

        // Only run synchronous blocking upload if explicitly requested ($immediateSync = true)
        if ($immediateSync && !empty($this->getRemoteEndpoint())) {
            $this->syncSession($sessionId);
            $queue = $this->loadQueue();
            return $queue[$sessionId] ?? $record;
        }

        return $record;
    }

    /**
     * Normalize a Cloud Worker / Viewer URL (strip query strings and trailing slashes)
     */
    public static function normalizeCloudBaseUrl(string $rawUrl): string
    {
        $trimmed = rtrim(trim($rawUrl), '/');
        $withoutQuery = preg_replace('/\?.*$/', '', $trimmed) ?? $trimmed;
        return rtrim($withoutQuery, '/');
    }

    /**
     * Load dynamic network_mode.json config with canonical defaults
     */
    public function getNetworkModeConfig(): array
    {
        $defaults = [
            'mode' => 'cloud_web',
            'venue_ip' => trim((string) ($this->config['qr']['local_ip'] ?? '')),
            'venue_ssid' => '',
            'cloud_url' => self::normalizeCloudBaseUrl(
                (string) ($this->config['qr']['custom_url'] ?? $this->config['qr']['cloud_url'] ?? self::DEFAULT_CLOUD_URL)
            ),
            'cloud_api_key' => trim((string) ($this->config['sync']['api_key'] ?? self::DEFAULT_API_KEY)),
            'auto_compress_cloud' => false,
            'updated_at' => time(),
        ];

        $file = PathUtility::getAbsolutePath('config/network_mode.json');
        if (file_exists($file)) {
            $raw = @file_get_contents($file);
            $decoded = $raw ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $merged = array_merge($defaults, $decoded);
                $merged['cloud_url'] = self::normalizeCloudBaseUrl((string) ($merged['cloud_url'] ?? ''));
                return $merged;
            }
        }
        return $defaults;
    }

    /**
     * Resolve QR target URL for a given photo filename according to active network mode (cloud_web or venue_wifi)
     */
    public function resolveQrTargetUrl(string $filename): string
    {
        $filename = basename($filename);
        $sessionId = pathinfo($filename, PATHINFO_FILENAME);
        $netCfg = $this->getNetworkModeConfig();
        $activeMode = (($netCfg['mode'] ?? 'cloud_web') === 'cloud_web' && !empty($netCfg['cloud_url']))
            ? 'cloud_web'
            : 'venue_wifi';
        $baseCloud = self::normalizeCloudBaseUrl((string) ($netCfg['cloud_url'] ?? ''));
        $localIp = trim((string) ($netCfg['venue_ip'] ?? ''));

        if ($activeMode === 'cloud_web' && $baseCloud !== '') {
            if (str_ends_with($baseCloud, '/view.php')) {
                return $baseCloud . '?s=' . rawurlencode($sessionId) . '&image=' . rawurlencode($filename);
            }
            return $baseCloud . '/?s=' . rawurlencode($sessionId);
        }

        if (!empty($this->config['ftp']['enabled']) && !empty($this->config['ftp']['useForQr'])) {
            $remoteStorageService = RemoteStorageService::getInstance();
            $url = $remoteStorageService->getWebpageUri();
            if (!empty($this->config['qr']['append_filename'])) {
                $url .= '/images/' . $filename;
            }
            return PathUtility::getPublicPath($url, true);
        }

        $url = (string) ($this->config['qr']['url'] ?? 'view.php?image=');
        if ($this->config['qr']['append_filename'] ?? true) {
            $url .= rawurlencode($filename);
        }
        $url = PathUtility::getPublicPath($url, true);

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
                ) ?? $url;
            }
        }

        return $url;
    }

    /**
     * Resolve the filename of the latest valid session photo in data/images.
     */
    public function resolveLatestSessionFilename(string $fallback = 'demo_session.jpg'): string
    {
        $imagesDir = FolderEnum::IMAGES->absolute();
        $latestFile = '';
        $latestMtime = 0;

        if (is_dir($imagesDir)) {
            $entries = @scandir($imagesDir) ?: [];
            foreach ($entries as $entry) {
                if (
                    !str_ends_with(strtolower($entry), '.jpg') ||
                    str_starts_with($entry, 'orig_') ||
                    str_starts_with($entry, 'raw_pose_') ||
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

        return $latestFile !== '' ? $latestFile : $fallback;
    }

    /**
     * Resolve QR code metadata & ensure qr_<sessionId>.png exists on disk for fast frontend rendering.
     *
     * @return array<string, mixed>
     */
    public function buildSessionQrData(string $filename, array $sessionMeta = [], bool $forceRegenerate = false): array
    {
        $filename = basename($filename);
        $sessionId = pathinfo($filename, PATHINFO_FILENAME);
        $netConfig = $this->getNetworkModeConfig();
        $activeMode = (($netConfig['mode'] ?? 'cloud_web') === 'cloud_web' && !empty($netConfig['cloud_url']))
            ? 'cloud_web'
            : 'venue_wifi';
        $customCloudUrl = trim((string) ($netConfig['cloud_url'] ?? ''));
        $localIp = trim((string) ($netConfig['venue_ip'] ?? ''));
        $venueSsid = trim((string) ($netConfig['venue_ssid'] ?? ''));

        $imagesDir = FolderEnum::IMAGES->absolute();
        $metaFilePath = $imagesDir . DIRECTORY_SEPARATOR . 'meta_' . $filename . '.json';
        $savedQrFileName = 'qr_' . $sessionId . '.png';
        $savedQrPath = $imagesDir . DIRECTORY_SEPARATOR . $savedQrFileName;

        if (empty($sessionMeta) && is_file($metaFilePath)) {
            $decodedMeta = json_decode((string) @file_get_contents($metaFilePath), true);
            if (is_array($decodedMeta)) {
                $sessionMeta = $decodedMeta;
            }
        }

        $url = $this->resolveQrTargetUrl($filename);
        $syncStatus = null;
        if ($activeMode === 'cloud_web' && $customCloudUrl !== '') {
            $rec = $this->getSessionRecord($sessionId);
            if (!$rec) {
                $rec = $this->enqueueSession($sessionId, ['final_photo' => $filename], false);
            }
            $syncStatus = $rec['status'] ?? 'pending';
        }

        $needGenerateQrFile = $forceRegenerate
            || !is_file($savedQrPath)
            || (($sessionMeta['qr_url'] ?? '') !== $url);

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
                // fallback to on-the-fly rendering via api/qrcode.php
            }
        }

        $qrPublicUrl = is_file($savedQrPath)
            ? PathUtility::getPublicPath('data/images/' . $savedQrFileName) . '?v=' . (int) @filemtime($savedQrPath)
            : PathUtility::getPublicPath('api/qrcode.php?filename=' . rawurlencode($filename) . '&mode=' . $activeMode . '&v=' . ($netConfig['updated_at'] ?? time()));

        return [
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
        ];
    }

    /**
     * Resolve remote sync endpoint for the active environment or Cloud Web mode
     */
    public function getRemoteEndpoint(): string
    {
        $netCfg = $this->getNetworkModeConfig();
        $cloudUrl = self::normalizeCloudBaseUrl((string) ($netCfg['cloud_url'] ?? ''));
        if ($cloudUrl !== '') {
            if (str_ends_with($cloudUrl, '.php') || str_ends_with($cloudUrl, '/api/upload')) {
                return $cloudUrl;
            }
            return $cloudUrl . '/api/syncReceive.php';
        }

        $env = $this->getEnvironmentProfile();
        $endpoints = $this->config['sync']['endpoints'] ?? [];
        if (is_array($endpoints) && !empty($endpoints[$env])) {
            return trim((string) $endpoints[$env]);
        }
        return trim((string) ($this->config['sync']['endpoint'] ?? ''));
    }

    /**
     * Resolve active API key for Cloud sync
     */
    protected function getApiKey(): string
    {
        $netCfg = $this->getNetworkModeConfig();
        if (!empty($netCfg['cloud_api_key'])) {
            return trim((string) $netCfg['cloud_api_key']);
        }
        return (string) ($this->config['sync']['api_key'] ?? self::DEFAULT_API_KEY);
    }

    /**
     * Synchronize a single session to the remote server.
     * Local files are NEVER deleted if network is down; status only becomes 'synced' on HTTP 200 + server confirmation.
     */
    public function syncSession(string $sessionId): array
    {
        $queue = $this->loadQueue();
        if (!isset($queue[$sessionId])) {
            return ['success' => false, 'error' => 'Session not found in queue'];
        }

        $item = $queue[$sessionId];
        $endpoint = $this->getRemoteEndpoint();

        if ($endpoint === '') {
            // Offline / Local-only mode: keep safe in local queue until endpoint is configured
            $item['status'] = 'local_stored';
            $item['last_error'] = 'No remote sync endpoint configured for environment: ' . $this->getEnvironmentProfile();
            $queue[$sessionId] = $item;
            $this->saveQueue($queue);
            return ['success' => true, 'mode' => 'local_only', 'record' => $item];
        }

        if (!function_exists('curl_init')) {
            $item['status'] = 'failed';
            $item['last_error'] = 'PHP cURL extension is not available.';
            $queue[$sessionId] = $item;
            $this->saveQueue($queue);
            return ['success' => false, 'error' => $item['last_error'], 'record' => $item];
        }

        // Concurrency lock per session to prevent parallel syncs from choking bandwidth
        $lockFile = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . 'sync_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $sessionId) . '.lock';
        $lockFp = @fopen($lockFile, 'c+');
        if ($lockFp && !@flock($lockFp, LOCK_EX | LOCK_NB)) {
            @fclose($lockFp);
            $this->logger->info("Session {$sessionId} is already being uploaded by another worker process, skipping duplicate run.");
            return ['success' => true, 'status' => 'already_syncing', 'record' => $item];
        }

        $item['attempts'] = ((int) ($item['attempts'] ?? 0)) + 1;
        $item['updated_at'] = time();

        $apiKey = $this->getApiKey();
        $netCfg = $this->getNetworkModeConfig();
        $autoCompress = (bool) ($netCfg['auto_compress_cloud'] ?? false);
        $tempCompressedFile = null;

        $imagesDir = FolderEnum::IMAGES->absolute();
        $finalFileName = basename((string) ($item['final_photo'] ?? ($sessionId . '.jpg')));

        // Refresh latest metadata from meta_<file>.json on disk if available
        $diskMetaFile = $imagesDir . DIRECTORY_SEPARATOR . 'meta_' . $finalFileName . '.json';
        if (is_file($diskMetaFile)) {
            $diskMeta = json_decode((string) @file_get_contents($diskMetaFile), true);
            if (is_array($diskMeta)) {
                $item['metadata'] = array_merge($item['metadata'] ?? [], $diskMeta);
            }
        }

        // Auto-detect companion GIF on disk even if it was baked after initial enqueue
        $gifFileName = !empty($item['gif_photo']) ? basename((string) $item['gif_photo']) : ($sessionId . '.gif');
        $gifPath = $imagesDir . DIRECTORY_SEPARATOR . $gifFileName;
        if (is_file($gifPath)) {
            $item['gif_photo'] = $gifFileName;
        }

        $postFields = [
            'session_id' => $sessionId,
            'environment' => $this->getEnvironmentProfile(),
            'metadata' => json_encode($item['metadata'] ?? [], JSON_UNESCAPED_UNICODE),
        ];

        $finalPath = $imagesDir . DIRECTORY_SEPARATOR . $finalFileName;
        $photoFileMTime = is_file($finalPath) ? (int) @filemtime($finalPath) : 0;
        $lastSyncedAt = (int) ($item['synced_at'] ?? 0);
        $photoIsNewer = $photoFileMTime > ($lastSyncedAt + 2);
        $needsPhotoUpload = is_file($finalPath) && (empty($item['synced_photo']) || $photoIsNewer);

        $gifNeedsUpload = is_file($gifPath) && empty($item['synced_gif']);

        // 1. Upload Final Photo first (if needed)
        if ($needsPhotoUpload) {
            $uploadPath = $finalPath;
            if ($autoCompress && filesize($finalPath) > 8 * 1024 * 1024) {
                $tempCompressedFile = $this->createCompressedCloudCopy($finalPath, 3200, 98);
                if ($tempCompressedFile !== null) {
                    $uploadPath = $tempCompressedFile;
                }
            }

            $photoFields = [
                'session_id' => $sessionId,
                'environment' => $this->getEnvironmentProfile(),
                'metadata' => json_encode($item['metadata'] ?? [], JSON_UNESCAPED_UNICODE),
                'final_photo' => new \CURLFile($uploadPath, 'image/jpeg', $finalFileName),
            ];

            $res = $this->executeCloudPost($endpoint, $photoFields, $apiKey, $sessionId, 120);
            if ($tempCompressedFile !== null && file_exists($tempCompressedFile)) {
                @unlink($tempCompressedFile);
            }

            if (!$res['success']) {
                if ($lockFp) {
                    @flock($lockFp, LOCK_UN);
                    @fclose($lockFp);
                    @unlink($lockFile);
                }
                $item['status'] = 'retry_pending';
                $item['last_error'] = $res['error'];
                $queue[$sessionId] = $item;
                $this->saveQueue($queue);
                $this->logger->warning("Photo upload failed for session {$sessionId}: {$res['error']}");
                return ['success' => false, 'error' => $res['error'], 'record' => $item];
            }

            $item['synced_photo'] = true;
            if (!empty($res['data']['share_url'])) {
                $item['remote_url'] = $res['data']['share_url'];
            }
            $queue[$sessionId] = $item;
            $this->saveQueue($queue);
        }

        // 2. Upload Companion Animated GIF sequentially (if available)
        if ($gifNeedsUpload) {
            $gifFields = [
                'session_id' => $sessionId,
                'environment' => $this->getEnvironmentProfile(),
                'metadata' => json_encode($item['metadata'] ?? [], JSON_UNESCAPED_UNICODE),
                'gif_photo' => new \CURLFile($gifPath, 'image/gif', $gifFileName),
            ];

            $res = $this->executeCloudPost($endpoint, $gifFields, $apiKey, $sessionId, 180);
            if (!$res['success']) {
                if ($lockFp) {
                    @flock($lockFp, LOCK_UN);
                    @fclose($lockFp);
                    @unlink($lockFile);
                }
                $item['status'] = 'retry_pending';
                $item['last_error'] = $res['error'];
                $queue[$sessionId] = $item;
                $this->saveQueue($queue);
                $this->logger->warning("GIF upload failed for session {$sessionId}: {$res['error']}");
                return ['success' => false, 'error' => $res['error'], 'record' => $item];
            }

            $item['synced_gif'] = true;
            if (!empty($res['data']['share_url'])) {
                $item['remote_url'] = $res['data']['share_url'];
            }
            $queue[$sessionId] = $item;
            $this->saveQueue($queue);
        }

        if ($lockFp) {
            @flock($lockFp, LOCK_UN);
            @fclose($lockFp);
            @unlink($lockFile);
        }

        $item['status'] = 'synced';
        $item['synced_at'] = time();
        $item['last_error'] = null;
        $queue[$sessionId] = $item;
        $this->saveQueue($queue);

        $this->logger->info("Session synced successfully: {$sessionId}");
        return ['success' => true, 'record' => $item];
    }

    /**
     * Helper to execute a standalone HTTP/1.1 POST upload to Cloudflare R2 Worker
     */
    protected function executeCloudPost(string $endpoint, array $postFields, string $apiKey, string $sessionId, int $timeout = 120): array
    {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'X-Photobooth-Api-Key: ' . $apiKey,
                'X-Photobooth-Session: ' . $sessionId,
                'Expect:',
            ],
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody !== false && $httpCode >= 200 && $httpCode < 300) {
            $decoded = json_decode((string) $responseBody, true);
            if (is_array($decoded) && !empty($decoded['confirmed']) && ($decoded['session_id'] ?? '') === $sessionId) {
                return ['success' => true, 'data' => $decoded];
            }
        }

        $err = $curlError !== '' ? $curlError : "Remote server returned HTTP {$httpCode}";
        return ['success' => false, 'error' => $err];
    }

    /**
     * Process all pending / retry_pending items in the queue
     */
    public function processPendingQueue(int $maxBatch = 10): array
    {
        $queue = $this->loadQueue();
        $processed = 0;
        $succeeded = 0;
        $failed = 0;

        // Prioritize newest sessions first so active guests at the venue get synced immediately
        $reversedQueue = array_reverse($queue, true);

        foreach ($reversedQueue as $sessionId => $item) {
            if ($processed >= $maxBatch) {
                break;
            }
            $status = $item['status'] ?? 'pending';
            if (in_array($status, ['pending', 'retry_pending', 'failed'], true)) {
                $processed++;
                $res = $this->syncSession((string) $sessionId);
                if (!empty($res['success'])) {
                    $succeeded++;
                } else {
                    $failed++;
                }
            }
        }

        return [
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'summary' => $this->getQueueSummary(),
        ];
    }

    /**
     * Get queue statistics and recent items
     */
    public function getQueueSummary(): array
    {
        $queue = $this->loadQueue();
        $counts = [
            'total' => count($queue),
            'pending' => 0,
            'retry_pending' => 0,
            'local_stored' => 0,
            'synced' => 0,
            'failed' => 0,
        ];

        foreach ($queue as $item) {
            $st = $item['status'] ?? 'pending';
            if (isset($counts[$st])) {
                $counts[$st]++;
            }
        }

        return [
            'environment' => $this->getEnvironmentProfile(),
            'remote_endpoint_configured' => ($this->getRemoteEndpoint() !== ''),
            'counts' => $counts,
            'items' => array_slice(array_reverse(array_values($queue)), 0, 25),
        ];
    }

    /**
     * Get a single session record by ID or by image filename
     */
    public function getSessionRecord(string $idOrFilename): ?array
    {
        $queue = $this->loadQueue();
        $cleanId = pathinfo($idOrFilename, PATHINFO_FILENAME);

        if (isset($queue[$idOrFilename])) {
            return $queue[$idOrFilename];
        }
        if (isset($queue[$cleanId])) {
            return $queue[$cleanId];
        }

        foreach ($queue as $item) {
            if (($item['final_photo'] ?? '') === $idOrFilename || ($item['gif_photo'] ?? '') === $idOrFilename) {
                return $item;
            }
        }

        return null;
    }

    protected function loadQueue(): array
    {
        if (!file_exists($this->queueFile)) {
            return [];
        }
        $raw = @file_get_contents($this->queueFile);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    protected function saveQueue(array $queue): bool
    {
        // Keep only the latest 30 sessions to prevent JSON bloat and keep I/O sub-millisecond
        if (count($queue) > 30) {
            $queue = array_slice($queue, -30, null, true);
        }
        $dir = dirname($this->queueFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return file_put_contents(
            $this->queueFile,
            json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        ) !== false;
    }

    /**
     * Create an optimized Full-HD JPEG copy for rapid Cloud upload without modifying the local print file
     */
    protected function createCompressedCloudCopy(string $sourcePath, int $maxDim = 1800, int $quality = 88): ?string
    {
        $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ptb_cloud_' . uniqid('', true) . '.jpg';
        if (extension_loaded('imagick')) {
            try {
                $im = new \Imagick($sourcePath);
                if ($im->getImageWidth() > $maxDim || $im->getImageHeight() > $maxDim) {
                    $im->thumbnailImage($maxDim, $maxDim, true, false);
                }
                $im->stripImage();
                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality($quality);
                $ok = $im->writeImage($tmpFile);
                $im->clear();
                $im->destroy();
                if ($ok && is_file($tmpFile)) {
                    return $tmpFile;
                }
            } catch (\Throwable) {
                // Fall back to GD below
            }
        }

        try {
            $info = @getimagesize($sourcePath);
            if (!$info) {
                return null;
            }
            [$w, $h, $type] = $info;
            $src = match ($type) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
                IMAGETYPE_PNG => @imagecreatefrompng($sourcePath),
                default => false,
            };
            if (!$src) {
                return null;
            }

            $scale = min(1.0, $maxDim / max($w, $h));
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));

            $dst = imagecreatetruecolor($nw, $nh);
            if (!$dst) {
                imagedestroy($src);
                return null;
            }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $ok = imagejpeg($dst, $tmpFile, $quality);
            imagedestroy($src);
            imagedestroy($dst);

            return ($ok && file_exists($tmpFile)) ? $tmpFile : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

