<?php

declare(strict_types=1);

namespace Photobooth\Service;

use Photobooth\Enum\FolderEnum;
use Photobooth\Utility\PathUtility;
use Psr\Log\LoggerInterface;

class SyncQueueService
{
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
     * Load dynamic network_mode.json config
     */
    public function getNetworkModeConfig(): array
    {
        $file = PathUtility::getAbsolutePath('config/network_mode.json');
        if (file_exists($file)) {
            $raw = @file_get_contents($file);
            $decoded = $raw ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    /**
     * Resolve remote sync endpoint for the active environment or Cloud Web mode
     */
    public function getRemoteEndpoint(): string
    {
        $netCfg = $this->getNetworkModeConfig();
        $cloudUrl = rtrim(trim((string) ($netCfg['cloud_url'] ?? '')), '/');
        if ($cloudUrl !== '') {
            // Strip query params if user pasted a viewer URL like https://.../?s=123
            $cloudUrl = preg_replace('/\?.*$/', '', $cloudUrl) ?? $cloudUrl;
            $cloudUrl = rtrim($cloudUrl, '/');
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
        return (string) ($this->config['sync']['api_key'] ?? '');
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

        $item['attempts'] = ((int) ($item['attempts'] ?? 0)) + 1;
        $item['updated_at'] = time();

        $apiKey = $this->getApiKey();
        $netCfg = $this->getNetworkModeConfig();
        $autoCompress = (bool) ($netCfg['auto_compress_cloud'] ?? true);
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

        // Attach final photo (optionally compressed to fast Full HD for instant 4G/Cloud upload)
        $finalPath = $imagesDir . DIRECTORY_SEPARATOR . $finalFileName;
        if (is_file($finalPath)) {
            $uploadPath = $finalPath;
            if ($autoCompress && filesize($finalPath) > 900 * 1024) {
                $tempCompressedFile = $this->createCompressedCloudCopy($finalPath);
                if ($tempCompressedFile !== null) {
                    $uploadPath = $tempCompressedFile;
                }
            }
            $postFields['final_photo'] = new \CURLFile($uploadPath, 'image/jpeg', $finalFileName);
        }

        // Attach animated GIF if available
        if (is_file($gifPath)) {
            $postFields['gif_photo'] = new \CURLFile($gifPath, 'image/gif', $gifFileName);
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'X-Photobooth-Api-Key: ' . $apiKey,
                'X-Photobooth-Session: ' . $sessionId,
            ],
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($tempCompressedFile !== null && file_exists($tempCompressedFile)) {
            @unlink($tempCompressedFile);
        }

        if ($responseBody !== false && $httpCode >= 200 && $httpCode < 300) {
            $decoded = json_decode((string) $responseBody, true);
            if (is_array($decoded) && !empty($decoded['confirmed']) && ($decoded['session_id'] ?? '') === $sessionId) {
                $item['status'] = 'synced';
                $item['synced_at'] = time();
                $item['last_error'] = null;
                $item['remote_url'] = $decoded['share_url'] ?? null;
                $queue[$sessionId] = $item;
                $this->saveQueue($queue);

                $this->logger->info("Session synced successfully: {$sessionId}");
                return ['success' => true, 'record' => $item];
            }
        }

        $err = $curlError !== '' ? $curlError : "Remote server returned HTTP {$httpCode}";
        $item['status'] = 'retry_pending';
        $item['last_error'] = $err;
        $queue[$sessionId] = $item;
        $this->saveQueue($queue);

        $this->logger->warning("Sync failed for session {$sessionId} (attempt {$item['attempts']}): {$err}");
        return ['success' => false, 'error' => $err, 'record' => $item];
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

        foreach ($queue as $sessionId => $item) {
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
    protected function createCompressedCloudCopy(string $sourcePath, int $maxDim = 1920, int $quality = 88): ?string
    {
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
            $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ptb_cloud_' . uniqid('', true) . '.jpg';
            $ok = imagejpeg($dst, $tmpFile, $quality);
            imagedestroy($src);
            imagedestroy($dst);

            return ($ok && file_exists($tmpFile)) ? $tmpFile : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

