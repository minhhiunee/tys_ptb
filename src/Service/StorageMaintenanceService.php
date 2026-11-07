<?php

declare(strict_types=1);

namespace Photobooth\Service;

use Photobooth\Enum\FolderEnum;
use Photobooth\Utility\PathUtility;
use Psr\Log\LoggerInterface;

/**
 * StorageMaintenanceService
 *
 * Automatically prunes expired temporary files (time-lapse frames, preset previews,
 * orphaned collage sub-files, and stale camera locks) to keep disk I/O fast and prevent
 * SSD exhaustion during long multi-hour events.
 */
class StorageMaintenanceService
{
    private static ?self $instance = null;
    protected LoggerInterface $logger;

    private function __construct()
    {
        $this->logger = LoggerService::getInstance()->getLogger('main');
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Run a lightweight probabilistic cleanup (e.g., 15% chance per capture or explicit call).
     */
    public function runProbabilisticCleanup(int $probabilityPercent = 15): void
    {
        if (mt_rand(1, 100) <= $probabilityPercent) {
            $this->pruneExpiredTempFiles();
        }
    }

    /**
     * Prune expired temporary files in data/tmp, system temp dir, and stale locks in var/run.
     *
     * @param int $previewMaxAgeSeconds Max age for preview_* and preview_base_* files (default: 1800s / 30m)
     * @param int $tempSessionMaxAgeSeconds Max age for tl_*, orig_*, and sub-collage files in tmp (default: 3600s / 1h)
     * @return array{deleted_count: int, freed_bytes: int}
     */
    public function pruneExpiredTempFiles(
        int $previewMaxAgeSeconds = 1800,
        int $tempSessionMaxAgeSeconds = 3600
    ): array {
        $now = time();
        $deletedCount = 0;
        $freedBytes = 0;

        try {
            $tmpDir = FolderEnum::TEMP->absolute();
            if (is_dir($tmpDir)) {
                $entries = @scandir($tmpDir);
                if (is_array($entries)) {
                    foreach ($entries as $entry) {
                        if ($entry === '.' || $entry === '..' || $entry === '.gitkeep' || $entry === 'index.html') {
                            continue;
                        }

                        $fullPath = $tmpDir . DIRECTORY_SEPARATOR . $entry;
                        if (!is_file($fullPath)) {
                            continue;
                        }

                        $mtime = @filemtime($fullPath);
                        if ($mtime === false) {
                            continue;
                        }

                        $age = $now - $mtime;
                        $shouldDelete = false;

                        // 1. Preset live preview files & preview_base files expire after 30 mins
                        if (str_starts_with($entry, 'preview_')) {
                            $shouldDelete = ($age > $previewMaxAgeSeconds);
                        }
                        // 2. Pre-scaled frame overlay caches expire after 24 hours
                        elseif (str_starts_with($entry, 'cache_frame_')) {
                            $shouldDelete = ($age > 86400);
                        }
                        // 3. Time-lapse temp frames, orig_ backups in tmp, meta_ in tmp expire after 1 hour
                        elseif (
                            str_starts_with($entry, 'tl_') ||
                            str_starts_with($entry, 'orig_') ||
                            str_starts_with($entry, 'meta_') ||
                            preg_match('/-\d+\.jpg$/i', $entry) ||
                            str_ends_with(strtolower($entry), '.jpg')
                        ) {
                            $shouldDelete = ($age > $tempSessionMaxAgeSeconds);
                        }

                        if ($shouldDelete) {
                            $size = (int) @filesize($fullPath);
                            if (@unlink($fullPath)) {
                                $deletedCount++;
                                $freedBytes += $size;
                            }
                        }
                    }
                }
            }

            // 3. Clean up any stray ptb_* files in system temp directory older than 15 minutes
            $sysTmp = sys_get_temp_dir();
            if (is_dir($sysTmp)) {
                $sysFiles = glob($sysTmp . DIRECTORY_SEPARATOR . 'ptb_*') ?: [];
                foreach ($sysFiles as $sysFile) {
                    if (is_file($sysFile)) {
                        $mtime = @filemtime($sysFile);
                        if ($mtime !== false && ($now - $mtime) > 900) {
                            $size = (int) @filesize($sysFile);
                            if (@unlink($sysFile)) {
                                $deletedCount++;
                                $freedBytes += $size;
                            }
                        }
                    }
                }
            }

            // 4. Clean up stale camera lock files (> 120 seconds old)
            $lockDir = PathUtility::getAbsolutePath('var/run');
            if (is_dir($lockDir)) {
                $locks = glob($lockDir . DIRECTORY_SEPARATOR . 'camera_*.lock') ?: [];
                foreach ($locks as $lockFile) {
                    if (is_file($lockFile)) {
                        $mtime = @filemtime($lockFile);
                        if ($mtime !== false && ($now - $mtime) > 120) {
                            @unlink($lockFile);
                        }
                    }
                }
            }

            if ($deletedCount > 0) {
                $this->logger->debug('StorageMaintenanceService pruned expired temporary files', [
                    'deleted_count' => $deletedCount,
                    'freed_kb' => round($freedBytes / 1024, 2),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('StorageMaintenanceService warning: ' . $e->getMessage());
        }

        return [
            'deleted_count' => $deletedCount,
            'freed_bytes' => $freedBytes,
        ];
    }
}
