<?php

declare(strict_types=1);

namespace Photobooth\Service;

use Photobooth\Enum\FolderEnum;
use Photobooth\Utility\PathUtility;
use Psr\Log\LoggerInterface;

/**
 * CaptureOneIntegrationService (Phases 3, 4, 5, 7, 8)
 *
 * External Image Processing Provider abstraction for Capture One Pro.
 * Coordinates session folders, pose job creation, file ingestion, idempotency tracking,
 * hybrid fallback to PresetService, and Admin diagnostics.
 */
class CaptureOneIntegrationService
{
    private static ?self $instance = null;
    protected LoggerInterface $logger;
    protected string $queueFile;
    protected string $heartbeatFile;

    public function __construct()
    {
        $this->logger = LoggerService::getInstance()->getLogger('capture-one');
        $this->queueFile = PathUtility::getAbsolutePath('data/c1_queue.json');
        $this->heartbeatFile = PathUtility::getAbsolutePath('data/c1_host_heartbeat.json');
        $this->ensureDirectoriesAndSession();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConfig(): array
    {
        $config = ConfigurationService::getInstance()->getConfiguration();
        $c1 = $config['capture_one'] ?? [];
        return is_array($c1) ? $c1 : [];
    }

    public function isEnabled(): bool
    {
        return !empty($this->getConfig()['enabled']);
    }

    public function getCameraMode(): string
    {
        $mode = (string) ($this->getConfig()['camera_mode'] ?? 'photobooth_owns_camera');
        return in_array($mode, ['photobooth_owns_camera', 'capture_one_owns_camera'], true)
            ? $mode
            : 'photobooth_owns_camera';
    }

    public function getInputFolderAbsolute(): string
    {
        $rel = trim((string) ($this->getConfig()['input_folder'] ?? 'data/c1_input'));
        return $rel !== '' ? PathUtility::getAbsolutePath($rel) : FolderEnum::C1_INPUT->absolute();
    }

    public function getOutputFolderAbsolute(): string
    {
        $rel = trim((string) ($this->getConfig()['output_folder'] ?? 'data/c1_output'));
        return $rel !== '' ? PathUtility::getAbsolutePath($rel) : FolderEnum::C1_OUTPUT->absolute();
    }

    public function getArchiveFolderAbsolute(): string
    {
        return FolderEnum::C1_ARCHIVE->absolute();
    }

    public function getDefaultStyle(): string
    {
        $style = trim((string) ($this->getConfig()['default_style'] ?? 'clean-portrait'));
        return $style !== '' ? $style : 'clean-portrait';
    }

    public function getProcessRecipe(): string
    {
        return trim((string) ($this->getConfig()['process_recipe'] ?? 'Photobooth_JPEG_sRGB'));
    }

    public function getOutputFormat(): string
    {
        $fmt = strtolower(trim((string) ($this->getConfig()['output_format'] ?? 'jpeg')));
        return in_array($fmt, ['jpeg', 'tiff'], true) ? $fmt : 'jpeg';
    }

    public function getJpegQuality(): int
    {
        return max(50, min(100, (int) ($this->getConfig()['jpeg_quality'] ?? 92)));
    }

    public function getOutputResolution(): int
    {
        return max(600, min(6000, (int) ($this->getConfig()['output_resolution'] ?? 2400)));
    }

    public function getProcessingTimeout(): int
    {
        return max(3, min(120, (int) ($this->getConfig()['processing_timeout'] ?? 25)));
    }

    public function getStabilityWaitMs(): int
    {
        return max(50, min(3000, (int) ($this->getConfig()['stability_wait_ms'] ?? 350)));
    }

    public function getRetryCount(): int
    {
        return max(0, min(5, (int) ($this->getConfig()['retry_count'] ?? 2)));
    }

    public function shouldFallbackToLocalPreset(): bool
    {
        $cfg = $this->getConfig();
        return !array_key_exists('fallback_to_local_preset', $cfg) || !empty($cfg['fallback_to_local_preset']);
    }

    /**
     * Ensure Capture One input, output, archive, and session structure exist.
     */
    public function ensureDirectoriesAndSession(): void
    {
        foreach ([
            $this->getInputFolderAbsolute(),
            $this->getOutputFolderAbsolute(),
            $this->getArchiveFolderAbsolute(),
            PathUtility::getAbsolutePath('data/c1_session'),
            PathUtility::getAbsolutePath('data/c1_session/Capture'),
            PathUtility::getAbsolutePath('data/c1_session/Output'),
            PathUtility::getAbsolutePath('data/c1_session/Selects'),
            PathUtility::getAbsolutePath('data/c1_session/Trash'),
        ] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            @chmod($dir, 0777);
        }
        $logFile = PathUtility::getAbsolutePath('var/log/capture-one.log');
        if (!is_file($logFile)) {
            @touch($logFile);
        }
        @chmod($logFile, 0666);
    }

    /**
     * Check whether the Windows Host Bridge / Capture One companion watcher is actively reporting heartbeat.
     */
    public function isHostBridgeAlive(int $maxAgeSeconds = 60): bool
    {
        if (!is_file($this->heartbeatFile)) {
            return false;
        }
        $raw = ltrim((string) @file_get_contents($this->heartbeatFile), "\xEF\xBB\xBF");
        $data = @json_decode($raw, true);
        if (!is_array($data) || empty($data['updated_at'])) {
            return false;
        }
        return abs(time() - (int) $data['updated_at']) <= $maxAgeSeconds;
    }

    /**
     * Process a captured pose through Capture One (or fallback cleanly to PresetService if offline).
     *
     * @param array<string, mixed> $options Optional overrides: ['force_wait_ms' => int, 'style' => string, 'amount' => int]
     * @return array{
     *   success: bool,
     *   provider: string,
     *   job_id: string,
     *   session_id: string,
     *   pose_index: int,
     *   style: string,
     *   elapsed_ms: float,
     *   fallback_used: bool,
     *   raw_backup_path: string|null,
     *   error?: string
     * }
     */
    public function processCapturedPose(
        string $poseFilePath,
        string $sessionId,
        int $poseIndex = 0,
        array $options = []
    ): array {
        $startNs = hrtime(true);
        $styleId = (string) ($options['style'] ?? $this->getDefaultStyle());
        $styleAmount = isset($options['amount']) ? (int) $options['amount'] : 100;
        $jobId = sprintf('c1_%s_p%02d', $sessionId, $poseIndex);

        // Preserve untouched raw pose BEFORE Capture One grading so Before/After and Hybrid Darkroom work
        $rawBackupPath = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . sprintf('raw_ungraded_%s_p%02d.jpg', $sessionId, $poseIndex);
        if (is_file($poseFilePath) && filesize($poseFilePath) > 0) {
            @copy($poseFilePath, $rawBackupPath);
        } else {
            $rawBackupPath = null;
        }

        $inputDir = $this->getInputFolderAbsolute();
        $outputDir = $this->getOutputFolderAbsolute();
        $inputFileName = $jobId . '.jpg';
        $inputFilePath = $inputDir . DIRECTORY_SEPARATOR . $inputFileName;
        $jobManifestPath = $inputDir . DIRECTORY_SEPARATOR . $jobId . '.job.json';

        $jobRecord = [
            'job_id' => $jobId,
            'session_id' => $sessionId,
            'pose_index' => $poseIndex,
            'camera_mode' => $this->getCameraMode(),
            'style' => $styleId,
            'style_amount' => $styleAmount,
            'process_recipe' => $this->getProcessRecipe(),
            'output_format' => $this->getOutputFormat(),
            'jpeg_quality' => $this->getJpegQuality(),
            'output_resolution' => $this->getOutputResolution(),
            'expected_filename' => $inputFileName,
            'input_path' => $inputFilePath,
            'target_path' => $poseFilePath,
            'raw_backup_path' => $rawBackupPath,
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => time(),
            'created_at_ms' => (int) round(microtime(true) * 1000),
            'updated_at' => time(),
        ];

        // Clear any stale output or trigger files from previous captures/retakes for this job
        $staleOutput = $outputDir . DIRECTORY_SEPARATOR . $inputFileName;
        if (is_file($staleOutput)) {
            @unlink($staleOutput);
        }
        $staleDone = $outputDir . DIRECTORY_SEPARATOR . $jobId . '.done.json';
        if (is_file($staleDone)) {
            @unlink($staleDone);
        }
        $staleTrigger = $inputDir . DIRECTORY_SEPARATOR . $jobId . '.trigger.json';
        if (is_file($staleTrigger)) {
            @unlink($staleTrigger);
        }

        $this->upsertJob($jobId, $jobRecord);

        // In capture_one_owns_camera mode, write a trigger manifest so host companion fires the camera shutter
        if ($this->getCameraMode() === 'capture_one_owns_camera') {
            $triggerManifestPath = $inputDir . DIRECTORY_SEPARATOR . $jobId . '.trigger.json';
            @file_put_contents(
                $triggerManifestPath,
                json_encode($jobRecord, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        } elseif ($this->getCameraMode() === 'photobooth_owns_camera' && is_file($poseFilePath)) {
            // In photobooth_owns_camera mode, drop the captured file + manifest into data/c1_input/
            @copy($poseFilePath, $inputFilePath);
            @file_put_contents(
                $jobManifestPath,
                json_encode($jobRecord, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        }

        // Determine effective wait budget:
        // - If Host Bridge is alive or force_wait_ms is set, wait up to full processing_timeout
        // - If Host Bridge is not alive and no pre-staged output exists, poll briefly (1200ms) before safe fallback
        $hostAlive = $this->isHostBridgeAlive();
        $hasStagedOutput = !empty(glob($outputDir . DIRECTORY_SEPARATOR . '*'));
        $maxWaitMs = isset($options['force_wait_ms'])
            ? (int) $options['force_wait_ms']
            : (($hostAlive || $hasStagedOutput) ? ($this->getProcessingTimeout() * 1000) : 1200);

        $retries = $this->getRetryCount();
        $attempt = 0;

        while ($attempt <= $retries) {
            $attempt++;
            $deadlineMs = (int) round(microtime(true) * 1000) + (int) round($maxWaitMs / max(1, $retries + 1));

            while ((int) round(microtime(true) * 1000) <= $deadlineMs) {
                $this->scanAndIngestOutputFolder();
                $currentJob = $this->getJob($jobId);
                if (is_array($currentJob) && ($currentJob['status'] ?? '') === 'completed') {
                    $elapsedMs = round((hrtime(true) - $startNs) / 1e6, 1);
                    $this->recordJobLatency($elapsedMs, false);
                    return [
                        'success' => true,
                        'provider' => 'capture_one',
                        'job_id' => $jobId,
                        'session_id' => $sessionId,
                        'pose_index' => $poseIndex,
                        'style' => $styleId,
                        'elapsed_ms' => $elapsedMs,
                        'fallback_used' => false,
                        'raw_backup_path' => $rawBackupPath,
                    ];
                }
                usleep(120000); // 120ms poll interval
            }
        }

        // Timeout or Host Bridge offline: clean up manifest but keep input image for audit/inspection
        @unlink($jobManifestPath);

        $errMsg = sprintf(
            'Capture One export timeout for job %s (mode=%s, host_alive=%s)',
            $jobId,
            $this->getCameraMode(),
            $hostAlive ? 'yes' : 'no'
        );

        if ($this->shouldFallbackToLocalPreset() && is_file($poseFilePath)) {
            $fallbackOk = true;
            if ($styleId !== '' && $styleId !== 'original' && $styleId !== 'none' && $styleAmount > 0) {
                $tmpFallback = $poseFilePath . '.c1fallback.jpg';
                $fallbackOk = PresetService::getInstance()->applyPreset(
                    $poseFilePath,
                    $tmpFallback,
                    $styleId,
                    $styleAmount,
                    null,
                    $this->getJpegQuality()
                );
                if ($fallbackOk && is_file($tmpFallback) && filesize($tmpFallback) > 0) {
                    @rename($tmpFallback, $poseFilePath);
                } else {
                    @unlink($tmpFallback);
                }
            }

            $elapsedMs = round((hrtime(true) - $startNs) / 1e6, 1);
            $jobRecord['status'] = 'fallback_completed';
            $jobRecord['fallback_reason'] = $errMsg;
            $jobRecord['elapsed_ms'] = $elapsedMs;
            $jobRecord['updated_at'] = time();
            $this->upsertJob($jobId, $jobRecord, $errMsg);
            $this->recordJobLatency($elapsedMs, true);

            $this->logger->warning($errMsg . ' -> Fell back to local PresetService.');

            return [
                'success' => $fallbackOk,
                'provider' => 'preset_service_fallback',
                'job_id' => $jobId,
                'session_id' => $sessionId,
                'pose_index' => $poseIndex,
                'style' => $styleId,
                'elapsed_ms' => $elapsedMs,
                'fallback_used' => true,
                'raw_backup_path' => $rawBackupPath,
                'error' => $errMsg,
            ];
        }

        $elapsedMs = round((hrtime(true) - $startNs) / 1e6, 1);
        $jobRecord['status'] = 'failed';
        $jobRecord['error'] = $errMsg;
        $jobRecord['updated_at'] = time();
        $this->upsertJob($jobId, $jobRecord, $errMsg);

        return [
            'success' => false,
            'provider' => 'capture_one',
            'job_id' => $jobId,
            'session_id' => $sessionId,
            'pose_index' => $poseIndex,
            'style' => $styleId,
            'elapsed_ms' => $elapsedMs,
            'fallback_used' => false,
            'raw_backup_path' => $rawBackupPath,
            'error' => $errMsg,
        ];
    }

    /**
     * Scan the Capture One output folder, verify file stability + MIME/EXIF + idempotency,
     * and deliver completed files to their associated Photobooth session/pose targets.
     *
     * @return array{scanned: int, ingested: int, duplicates_skipped: int, invalid_skipped: int, items: array<int, array<string, mixed>>}
     */
    public function scanAndIngestOutputFolder(?int $customStabilityWaitMs = null): array
    {
        $outputDir = $this->getOutputFolderAbsolute();
        if (!is_dir($outputDir)) {
            return ['scanned' => 0, 'ingested' => 0, 'duplicates_skipped' => 0, 'invalid_skipped' => 0, 'items' => []];
        }

        $entries = @scandir($outputDir);
        if (!is_array($entries)) {
            return ['scanned' => 0, 'ingested' => 0, 'duplicates_skipped' => 0, 'invalid_skipped' => 0, 'items' => []];
        }

        $ingestionService = CaptureOneIngestionService::getInstance();
        $stabilityMs = $customStabilityWaitMs ?? $this->getStabilityWaitMs();
        $scanned = 0;
        $ingested = 0;
        $duplicates = 0;
        $invalid = 0;
        $items = [];

        natsort($entries);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.gitkeep' || str_starts_with($entry, '.')) {
                continue;
            }
            $ext = strtolower((string) pathinfo($entry, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'tif', 'tiff'], true)) {
                continue;
            }

            $scanned++;
            $fullPath = $outputDir . DIRECTORY_SEPARATOR . $entry;
            $check = $ingestionService->verifyFileStabilityAndValidity($fullPath, $stabilityMs);
            if (empty($check['valid'])) {
                $invalid++;
                $this->logger->debug("Skipping unstable/invalid file {$entry}: " . ($check['reason'] ?? 'unknown'));
                continue;
            }

            $sha1 = (string) ($check['sha1'] ?? '');
            $queue = $this->loadQueueData();
            $jobs = $queue['jobs'] ?? [];
            $assoc = $ingestionService->resolveSessionAndPose($entry, $jobs);
            $jobId = $assoc['job_id'];
            $sessionId = (string) ($assoc['session_id'] ?? 'unknown');
            $poseIndex = (int) ($assoc['pose_index'] ?? 0);

            // Skip and clean un-associated external files (e.g. Capture One recipe background exports like "Photobooth 00043.jpg")
            if ($jobId === null && empty($assoc['session_id'])) {
                $this->logger->debug("Cleaning unassociated output file: {$entry}");
                @unlink($fullPath);
                continue;
            }

            // Stale file check: if this file was created before the active job was queued, it belongs to an old capture
            if ($jobId !== null && isset($jobs[$jobId]['created_at'])) {
                $jobCreatedAt = (int) $jobs[$jobId]['created_at'];
                $fileMtime = @filemtime($fullPath);
                if ($jobCreatedAt > 0 && $fileMtime !== false && $fileMtime < ($jobCreatedAt - 1)) {
                    $this->logger->warning("Skipping and unlinking stale file {$entry} (mtime={$fileMtime} < job_created={$jobCreatedAt})");
                    @unlink($fullPath);
                    continue;
                }
            }

            // Idempotency key: prevent the exact same export for the same session/pose from being ingested twice
            $idempotencyKey = $sessionId . ':p' . $poseIndex . ':' . $sha1;
            if ($sha1 !== '' && isset($queue['processed_hashes'][$idempotencyKey])) {
                $duplicates++;
                $dupArchive = $this->getArchiveFolderAbsolute() . DIRECTORY_SEPARATOR . 'dup_' . time() . '_' . $entry;
                @rename($fullPath, $dupArchive);
                $this->logger->info("Duplicate Capture One export skipped via idempotency check: {$entry}");
                continue;
            }

            // Determine destination path in Photobooth tmp/images
            $targetPath = null;
            if ($jobId !== null && isset($jobs[$jobId]['target_path'])) {
                $targetPath = (string) $jobs[$jobId]['target_path'];
            } else {
                $targetPath = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . (
                    $poseIndex > 0 ? sprintf('%s-%d.jpg', $sessionId, $poseIndex) : sprintf('%s.jpg', $sessionId)
                );
            }

            $delivered = $ingestionService->deliverIngestedFile(
                $fullPath,
                $targetPath,
                (string) ($check['mime'] ?? 'image/jpeg'),
                $this->getJpegQuality()
            );

            if (!$delivered) {
                $invalid++;
                $this->recordError("Failed to deliver ingested file {$entry} to {$targetPath}");
                continue;
            }

            // Archive or remove staging files
            $archivePath = $this->getArchiveFolderAbsolute() . DIRECTORY_SEPARATOR . sprintf('%s_p%02d_%s.jpg', $sessionId, $poseIndex, date('His'));
            if (!@rename($fullPath, $archivePath)) {
                @unlink($fullPath);
            }
            if ($jobId !== null) {
                @unlink($this->getInputFolderAbsolute() . DIRECTORY_SEPARATOR . $jobId . '.jpg');
                @unlink($this->getInputFolderAbsolute() . DIRECTORY_SEPARATOR . $jobId . '.job.json');
            }

            // Prune old archive files (keep at most 20 latest)
            $this->pruneArchiveDirectory(20);

            $now = time();
            $ingestedItem = [
                'job_id' => $jobId ?? sprintf('c1_%s_p%02d', $sessionId, $poseIndex),
                'source_file' => $entry,
                'target_path' => basename($targetPath),
                'session_id' => $sessionId,
                'pose_index' => $poseIndex,
                'width' => $check['width'] ?? 0,
                'height' => $check['height'] ?? 0,
                'size_bytes' => $check['size'] ?? 0,
                'mime' => $check['mime'] ?? 'image/jpeg',
                'sha1' => $sha1,
                'exif' => $check['exif'] ?? [],
                'ingested_at' => $now,
            ];

            $this->markJobIngested($ingestedItem['job_id'], $idempotencyKey, $ingestedItem);
            $ingested++;
            $items[] = $ingestedItem;
            $this->logger->info('Ingested Capture One processed image', $ingestedItem);
        }

        return [
            'scanned' => $scanned,
            'ingested' => $ingested,
            'duplicates_skipped' => $duplicates,
            'invalid_skipped' => $invalid,
            'items' => $items,
        ];
    }

    /**
     * Discover available Capture One Styles (.costyle) and XMP presets in PresetColor/
     *
     * @return array<int, array<string, mixed>>
     */
    public function discoverAvailableStyles(): array
    {
        $presetDir = PathUtility::getAbsolutePath('PresetColor');
        $styles = [];
        if (is_dir($presetDir)) {
            $files = @scandir($presetDir) ?: [];
            foreach ($files as $f) {
                if ($f === '.' || $f === '..') {
                    continue;
                }
                $ext = strtolower((string) pathinfo($f, PATHINFO_EXTENSION));
                if (in_array($ext, ['costyle', 'xmp', 'cube'], true)) {
                    $styles[] = [
                        'filename' => $f,
                        'name' => pathinfo($f, PATHINFO_FILENAME),
                        'format' => $ext === 'costyle' ? 'capture_one_style' : $ext,
                        'size_bytes' => (int) @filesize($presetDir . DIRECTORY_SEPARATOR . $f),
                    ];
                }
            }
        }
        return $styles;
    }

    /**
     * Comprehensive diagnostics report for Admin Dashboard (Phase 8).
     */
    public function getDiagnostics(): array
    {
        $cfg = $this->getConfig();
        $inputDir = $this->getInputFolderAbsolute();
        $outputDir = $this->getOutputFolderAbsolute();
        $sessionPath = PathUtility::getAbsolutePath((string) ($cfg['session_path'] ?? 'data/c1_session/Photobooth.cosessiondb'));
        $sessionDir = is_dir($sessionPath) ? $sessionPath : dirname($sessionPath);

        $queue = $this->loadQueueData();
        $jobs = $queue['jobs'] ?? [];
        $pendingCount = 0;
        foreach ($jobs as $j) {
            if (($j['status'] ?? '') === 'pending') {
                $pendingCount++;
            }
        }

        $inputFiles = array_values(array_filter(
            @scandir($inputDir) ?: [],
            static fn($f) => !in_array($f, ['.', '..', '.gitkeep'], true)
        ));
        $outputFiles = array_values(array_filter(
            @scandir($outputDir) ?: [],
            static fn($f) => !in_array($f, ['.', '..', '.gitkeep'], true)
        ));

        $heartbeat = is_file($this->heartbeatFile)
            ? (@json_decode(ltrim((string) @file_get_contents($this->heartbeatFile), "\xEF\xBB\xBF"), true) ?: null)
            : null;

        return [
            'enabled' => $this->isEnabled(),
            'camera_mode' => $this->getCameraMode(),
            'executable_path' => (string) ($cfg['executable_path'] ?? ''),
            'host_bridge_alive' => $this->isHostBridgeAlive(),
            'host_heartbeat' => $heartbeat,
            'session' => [
                'configured_path' => (string) ($cfg['session_path'] ?? 'data/c1_session/Photobooth.cosessiondb'),
                'directory_exists' => is_dir($sessionDir),
                'writable' => is_dir($sessionDir) && is_writable($sessionDir),
            ],
            'folders' => [
                'input_folder' => $cfg['input_folder'] ?? 'data/c1_input',
                'input_exists' => is_dir($inputDir),
                'input_writable' => is_dir($inputDir) && is_writable($inputDir),
                'input_pending_files' => count($inputFiles),
                'output_folder' => $cfg['output_folder'] ?? 'data/c1_output',
                'output_exists' => is_dir($outputDir),
                'output_writable' => is_dir($outputDir) && is_writable($outputDir),
                'output_staged_files' => count($outputFiles),
            ],
            'workflow' => [
                'default_style' => $this->getDefaultStyle(),
                'process_recipe' => $this->getProcessRecipe(),
                'output_format' => $this->getOutputFormat(),
                'jpeg_quality' => $this->getJpegQuality(),
                'output_resolution' => $this->getOutputResolution(),
                'processing_timeout' => $this->getProcessingTimeout(),
                'stability_wait_ms' => $this->getStabilityWaitMs(),
                'retry_count' => $this->getRetryCount(),
                'fallback_to_local_preset' => $this->shouldFallbackToLocalPreset(),
            ],
            'discovered_styles' => $this->discoverAvailableStyles(),
            'queue' => [
                'pending_jobs' => $pendingCount,
                'total_tracked_jobs' => count($jobs),
                'idempotency_hashes_count' => count($queue['processed_hashes'] ?? []),
                'stats' => $queue['stats'] ?? [
                    'total_jobs' => 0,
                    'completed_c1' => 0,
                    'fallback_used' => 0,
                    'failed' => 0,
                    'avg_latency_ms' => 0.0,
                ],
                'last_processed' => $queue['last_processed'] ?? null,
                'recent_errors' => array_slice($queue['recent_errors'] ?? [], -10),
            ],
        ];
    }

    /**
     * Execute an non-destructive end-to-end self-test of the Capture One ingestion & stability pipeline.
     */
    public function runDiagnosticSelfTest(): array
    {
        $t0 = hrtime(true);
        $sessionId = 'c1diag_' . date('His') . '_' . mt_rand(100, 999);
        $poseIdx = 0;
        $jobId = sprintf('c1_%s_p%02d', $sessionId, $poseIdx);
        $targetTmp = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . $sessionId . '.jpg';
        $stagedOutput = $this->getOutputFolderAbsolute() . DIRECTORY_SEPARATOR . $jobId . '.jpg';

        // Create a synthetic test capture and a simulated Capture One processed export
        $im = imagecreatetruecolor(800, 600);
        $bg = imagecolorallocate($im, 42, 68, 112);
        $fg = imagecolorallocate($im, 240, 210, 150);
        imagefill($im, 0, 0, $bg);
        imagestring($im, 5, 40, 280, 'Capture One Self-Test ' . $sessionId, $fg);
        imagejpeg($im, $targetTmp, 90);

        // Simulate Capture One warm grade output in data/c1_output/
        imagefilter($im, IMG_FILTER_COLORIZE, 18, 8, -10);
        imagejpeg($im, $stagedOutput, 92);
        imagedestroy($im);

        $res = $this->processCapturedPose($targetTmp, $sessionId, $poseIdx, [
            'force_wait_ms' => 1500,
            'style' => $this->getDefaultStyle(),
        ]);

        // Test idempotency by dropping the exact same output file a second time
        @copy($targetTmp, $stagedOutput);
        $dupScan = $this->scanAndIngestOutputFolder(60);

        // Clean up diagnostic files
        @unlink($targetTmp);
        @unlink($stagedOutput);
        if (!empty($res['raw_backup_path']) && is_file((string) $res['raw_backup_path'])) {
            @unlink((string) $res['raw_backup_path']);
        }
        foreach (glob($this->getArchiveFolderAbsolute() . DIRECTORY_SEPARATOR . '*' . $sessionId . '*') ?: [] as $af) {
            @unlink($af);
        }

        $totalMs = round((hrtime(true) - $t0) / 1e6, 1);
        return [
            'passed' => !empty($res['success']) && ($res['provider'] ?? '') === 'capture_one' && ($dupScan['duplicates_skipped'] ?? 0) >= 1,
            'ingestion_result' => $res,
            'idempotency_duplicate_skipped' => ($dupScan['duplicates_skipped'] ?? 0) >= 1,
            'total_self_test_ms' => $totalMs,
        ];
    }

    /**
     * Load queue JSON safely with shared lock.
     */
    public function loadQueueData(): array
    {
        $default = [
            'jobs' => [],
            'processed_hashes' => [],
            'last_processed' => null,
            'stats' => [
                'total_jobs' => 0,
                'completed_c1' => 0,
                'fallback_used' => 0,
                'failed' => 0,
                'avg_latency_ms' => 0.0,
            ],
            'recent_errors' => [],
        ];

        if (!is_file($this->queueFile)) {
            return $default;
        }

        $fp = @fopen($this->queueFile, 'rb');
        if ($fp === false) {
            return $default;
        }
        @flock($fp, LOCK_SH);
        $raw = (string) @stream_get_contents($fp);
        @flock($fp, LOCK_UN);
        @fclose($fp);

        $decoded = @json_decode($raw, true);
        return is_array($decoded) ? array_replace_recursive($default, $decoded) : $default;
    }

    protected function saveQueueData(array $data): void
    {
        // Keep jobs & processed_hashes bounded to prevent unbounded growth during multi-day events
        if (isset($data['jobs']) && is_array($data['jobs']) && count($data['jobs']) > 120) {
            $data['jobs'] = array_slice($data['jobs'], -120, null, true);
        }
        if (isset($data['processed_hashes']) && is_array($data['processed_hashes']) && count($data['processed_hashes']) > 300) {
            $data['processed_hashes'] = array_slice($data['processed_hashes'], -300, null, true);
        }
        if (isset($data['recent_errors']) && is_array($data['recent_errors']) && count($data['recent_errors']) > 25) {
            $data['recent_errors'] = array_slice($data['recent_errors'], -25);
        }

        @file_put_contents(
            $this->queueFile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        @chmod($this->queueFile, 0666);
    }

    public function getJob(string $jobId): ?array
    {
        $q = $this->loadQueueData();
        return $q['jobs'][$jobId] ?? null;
    }

    protected function upsertJob(string $jobId, array $jobRecord, ?string $errorMsg = null): void
    {
        $q = $this->loadQueueData();
        $isNew = !isset($q['jobs'][$jobId]);
        $q['jobs'][$jobId] = $jobRecord;
        if ($isNew) {
            $q['stats']['total_jobs'] = (int) ($q['stats']['total_jobs'] ?? 0) + 1;
        }
        if (($jobRecord['status'] ?? '') === 'fallback_completed') {
            $q['stats']['fallback_used'] = (int) ($q['stats']['fallback_used'] ?? 0) + 1;
        } elseif (($jobRecord['status'] ?? '') === 'failed') {
            $q['stats']['failed'] = (int) ($q['stats']['failed'] ?? 0) + 1;
        }
        if ($errorMsg !== null) {
            $q['recent_errors'][] = [
                'time' => date('Y-m-d H:i:s'),
                'job_id' => $jobId,
                'message' => $errorMsg,
            ];
        }
        $this->saveQueueData($q);
    }

    protected function markJobIngested(string $jobId, string $idempotencyKey, array $ingestedItem): void
    {
        $q = $this->loadQueueData();
        if (isset($q['jobs'][$jobId])) {
            $q['jobs'][$jobId]['status'] = 'completed';
            $q['jobs'][$jobId]['updated_at'] = time();
            $q['jobs'][$jobId]['ingested_item'] = $ingestedItem;
        } else {
            $q['jobs'][$jobId] = [
                'job_id' => $jobId,
                'session_id' => $ingestedItem['session_id'],
                'pose_index' => $ingestedItem['pose_index'],
                'status' => 'completed',
                'created_at' => time(),
                'updated_at' => time(),
                'ingested_item' => $ingestedItem,
            ];
            $q['stats']['total_jobs'] = (int) ($q['stats']['total_jobs'] ?? 0) + 1;
        }
        $q['processed_hashes'][$idempotencyKey] = [
            'job_id' => $jobId,
            'ingested_at' => $ingestedItem['ingested_at'],
        ];
        $q['last_processed'] = $ingestedItem;
        $q['stats']['completed_c1'] = (int) ($q['stats']['completed_c1'] ?? 0) + 1;
        $this->saveQueueData($q);
    }

    protected function recordJobLatency(float $elapsedMs, bool $wasFallback): void
    {
        $q = $this->loadQueueData();
        $prevAvg = (float) ($q['stats']['avg_latency_ms'] ?? 0.0);
        $count = max(1, (int) ($q['stats']['completed_c1'] ?? 0) + (int) ($q['stats']['fallback_used'] ?? 0));
        $q['stats']['avg_latency_ms'] = round($prevAvg + (($elapsedMs - $prevAvg) / $count), 1);
        $this->saveQueueData($q);
    }

    protected function recordError(string $message): void
    {
        $q = $this->loadQueueData();
        $q['recent_errors'][] = [
            'time' => date('Y-m-d H:i:s'),
            'message' => $message,
        ];
        $this->saveQueueData($q);
    }

    protected function pruneArchiveDirectory(int $keepCount = 20): void
    {
        $archDir = $this->getArchiveFolderAbsolute();
        $files = glob($archDir . DIRECTORY_SEPARATOR . '*') ?: [];
        if (count($files) <= $keepCount) {
            return;
        }
        usort($files, static fn($a, $b) => ((int) @filemtime($a)) <=> ((int) @filemtime($b)));
        $toDelete = array_slice($files, 0, count($files) - $keepCount);
        foreach ($toDelete as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }
}
