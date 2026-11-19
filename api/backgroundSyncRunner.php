<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/../lib/boot.php';

use Photobooth\Collage;
use Photobooth\Enum\FolderEnum;
use Photobooth\Processor\GifProcessor;
use Photobooth\Service\LoggerService;
use Photobooth\Service\SyncQueueService;

$logger = LoggerService::getInstance()->getLogger('sync-runner');

$sessionId = $argv[1] ?? '';
if (empty($sessionId)) {
    exit(0);
}

$syncService = SyncQueueService::getInstance();

// 1. Synchronize static photo & metadata to Cloudflare R2 immediately (full uncompressed quality)
try {
    $resPhoto = $syncService->syncSession($sessionId);
    $logger->info('Background phase 1 (static photo) sync for ' . $sessionId, $resPhoto);
} catch (\Throwable $e) {
    $logger->error('Background phase 1 sync failed for ' . $sessionId . ': ' . $e->getMessage());
}

// 2. Ensure companion high-definition animated GIF (527x1560, 18 ticks + hold frames) is generated if missing
$gifBaked = false;
try {
    $imagesDir = FolderEnum::IMAGES->absolute();
    $gifTargetPath = $imagesDir . DIRECTORY_SEPARATOR . $sessionId . '.gif';
    $metaPath = $imagesDir . DIRECTORY_SEPARATOR . 'meta_' . $sessionId . '.jpg.json';

    if (!is_file($gifTargetPath) && is_file($metaPath)) {
        $meta = json_decode((string) file_get_contents($metaPath), true);
        if (is_array($meta)) {
            $gifProcessor = new GifProcessor();

            if (!empty($meta['isCollage']) && !empty($meta['layout'])) {
                $layoutConfigPath = Collage::getCollageConfigPath(
                    (string) $meta['layout'],
                    (string) ($meta['orientation'] ?? 'portrait')
                );
                if ($layoutConfigPath && is_file($layoutConfigPath)) {
                    $layoutData = json_decode((string) file_get_contents($layoutConfigPath), true);
                    if (is_array($layoutData) && !empty($layoutData['layout'])) {
                        $origPoses = is_array($meta['original_photos'] ?? null) ? $meta['original_photos'] : [];
                        $tlByPose = is_array($meta['timelapse_by_pose'] ?? null) ? $meta['timelapse_by_pose'] : [];
                        $poseCount = max(1, count($origPoses), count($tlByPose));
                        $poseFrameSequences = [];

                        for ($p = 0; $p < $poseCount; $p++) {
                            $seq = [];
                            if (!empty($tlByPose[$p]) && is_array($tlByPose[$p])) {
                                foreach ($tlByPose[$p] as $tlName) {
                                    $absTl = $imagesDir . DIRECTORY_SEPARATOR . basename((string) $tlName);
                                    if (is_file($absTl)) {
                                        $seq[] = $absTl;
                                    }
                                }
                            }
                            if (isset($origPoses[$p])) {
                                $absPose = $imagesDir . DIRECTORY_SEPARATOR . basename((string) $origPoses[$p]);
                                if (is_file($absPose)) {
                                    $seq[] = $absPose;
                                }
                            }
                            if (!empty($seq)) {
                                $poseFrameSequences[$p] = $seq;
                            }
                        }

                        if (!empty($poseFrameSequences)) {
                            $finalFramedPath = $imagesDir . DIRECTORY_SEPARATOR . basename((string) ($meta['final_photo'] ?? ($sessionId . '.jpg')));
                            $gifBaked = $gifProcessor->createFramedTimelapseGif(
                                $poseFrameSequences,
                                $layoutData,
                                $gifTargetPath,
                                140,
                                1560,
                                isset($meta['preset']) ? (string) $meta['preset'] : null,
                                (int) ($meta['preset_amount'] ?? 100),
                                is_file($finalFramedPath) ? $finalFramedPath : null
                            );
                        }
                    }
                }
            }

            if (!$gifBaked) {
                $gifSourceNames = !empty($meta['timelapse_photos']) && is_array($meta['timelapse_photos'])
                    ? $meta['timelapse_photos']
                    : ($meta['original_photos'] ?? []);
                if (is_array($gifSourceNames) && !empty($gifSourceNames)) {
                    $poseAbsPaths = [];
                    foreach ($gifSourceNames as $poseName) {
                        $candidatePose = $imagesDir . DIRECTORY_SEPARATOR . basename((string) $poseName);
                        if (is_file($candidatePose)) {
                            $poseAbsPaths[] = $candidatePose;
                        }
                    }
                    if (!empty($poseAbsPaths)) {
                        $gifBaked = $gifProcessor->createAnimatedGif(
                            $poseAbsPaths,
                            $gifTargetPath,
                            140,
                            1200
                        );
                    }
                }
            }

            if ($gifBaked && is_file($gifTargetPath)) {
                $logger->info("Background baked companion GIF for session {$sessionId} (size: " . filesize($gifTargetPath) . " bytes)");
            }
        }
    }
} catch (\Throwable $gifErr) {
    $logger->warning("Background GIF generation error for {$sessionId}: " . $gifErr->getMessage());
}

// 3. Push the newly generated animated GIF to Cloudflare R2
try {
    $result = $syncService->syncSession($sessionId);
    $logger->info('Background phase 3 (GIF & session) sync completed for ' . $sessionId, $result);
} catch (\Throwable $e) {
    $logger->error('Background phase 3 sync failed for ' . $sessionId . ': ' . $e->getMessage());
}

// 4. Gradually drain any previous pending/retry sessions in the queue (3-5 min background catch-up)
try {
    $queueRes = $syncService->processPendingQueue(5);
    $logger->info('Background pending queue sweep completed', $queueRes);
} catch (\Throwable $e) {
    $logger->warning('Background pending queue sweep failed: ' . $e->getMessage());
}
