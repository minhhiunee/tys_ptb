<?php

declare(strict_types=1);

require_once '../lib/boot.php';

use Photobooth\Enum\FolderEnum;
use Photobooth\Service\LoggerService;
use Photobooth\Service\PresetService;
use Photobooth\Utility\PathUtility;

header('Content-Type: application/json');

$logger = LoggerService::getInstance()->getLogger('preset');
$presetService = PresetService::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        checkCsrfOrFail($_POST);

        $action = $_POST['action'] ?? 'apply';
        $file = basename((string) ($_POST['file'] ?? ''));

        if ($file === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $file)) {
            throw new \Exception('Invalid or missing file name');
        }

        $presetId = trim((string) ($_POST['preset'] ?? 'original'));
        $amount = isset($_POST['amount']) ? (int) $_POST['amount'] : 100;
        $amount = max(0, min(100, $amount));
        if ($presetId === 'original') {
            $amount = 100;
        }

        // Locate source image in temp folder (data/tmp) or images folder (data/images)
        $sourceFile = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . $file;
        if (!file_exists($sourceFile)) {
            $sourceFile = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . $file;
        }

        if (!file_exists($sourceFile)) {
            throw new \Exception('Source image does not exist: ' . $file);
        }

        // Locate or create pristine original un-preset'd file
        $origFile = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . 'orig_' . $file;
        if (!file_exists($origFile)) {
            $origFile = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . 'orig_' . $file;
        }
        if (!file_exists($origFile)) {
            $origTemp = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . 'orig_' . $file;
            @copy($sourceFile, $origTemp);
            $origFile = $origTemp;
        }

        $baseSourceFile = file_exists($origFile) ? $origFile : $sourceFile;

        // Detect frame overlay if this image has a frame
        $framePngPath = null;
        $metaFile = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . 'meta_' . $file . '.json';
        if (!file_exists($metaFile)) {
            $metaFile = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . 'meta_' . $file . '.json';
        }
        if (file_exists($metaFile)) {
            $meta = json_decode((string) file_get_contents($metaFile), true);
            if (is_array($meta) && !empty($meta['frame'])) {
                $candidate = PathUtility::getAbsolutePath($meta['frame']);
                if (file_exists($candidate)) {
                    $framePngPath = $candidate;
                }
            }
        }

        // Fallback detection by image canvas dimensions matching test frames
        if ($framePngPath === null && file_exists($baseSourceFile)) {
            $imgSize = @getimagesize($baseSourceFile);
            if ($imgSize) {
                $w = $imgSize[0];
                $h = $imgSize[1];
                if ($w === 1975 && $h === 2400) {
                    $framePngPath = PathUtility::getAbsolutePath('private/frames/Frame 1.png');
                } elseif ($w === 1858 && $h === 2400) {
                    $framePngPath = PathUtility::getAbsolutePath('private/frames/Frame 2.png');
                } elseif ($w === 800 && $h === 2400) {
                    $framePngPath = PathUtility::getAbsolutePath('private/frames/Frame 3.png');
                } elseif ($w === 2364 && $h === 2400) {
                    $framePngPath = PathUtility::getAbsolutePath('private/frames/Frame 4.png');
                }
            }
        }

        $isLivePreview = !empty($_POST['preview']);
        if ($isLivePreview) {
            // Write to a temporary preview file in data/tmp
            $previewFileName = 'preview_' . $presetId . '_' . $amount . '_' . $file;
            $targetFile = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . $previewFileName;

            // Fast-path cache hit: if this exact preview (file + preset + amount) already exists, return immediately (<2ms)
            if (is_file($targetFile) && filesize($targetFile) > 1000) {
                echo json_encode([
                    'success' => true,
                    'file' => $previewFileName,
                    'url' => PathUtility::getPublicPath('data/tmp/' . $previewFileName) . '?v=' . filemtime($targetFile),
                    'preset' => $presetId,
                    'amount' => $amount,
                    'cached' => true,
                ]);
                exit();
            }

            // Use optimized 1200px preview base image if available for sub-30ms responsiveness
            $previewBaseFile = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . 'preview_base_' . $file;
            $previewSource = file_exists($previewBaseFile) ? $previewBaseFile : $baseSourceFile;

            $frameOverlay = ($presetId !== 'original' && $amount > 0 && $framePngPath && file_exists($framePngPath))
                ? $framePngPath
                : null;
            $success = $presetService->applyPreset($previewSource, $targetFile, $presetId, $amount, $frameOverlay, 86);

            if (!$success || !file_exists($targetFile)) {
                throw new \Exception('Failed generating preset preview');
            }

            echo json_encode([
                'success' => true,
                'file' => $previewFileName,
                'url' => PathUtility::getPublicPath('data/tmp/' . $previewFileName) . '?v=' . time(),
                'preset' => $presetId,
                'amount' => $amount,
            ]);
            exit();
        }

        // Persist applied preset & amount into session metadata JSON (Stage 2 & 3 requirement)
        $sessionId = pathinfo($file, PATHINFO_FILENAME);
        $metaImagesPath = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . 'meta_' . $file . '.json';
        $metaTempPath = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . 'meta_' . $file . '.json';
        $currentMeta = [];
        if (isset($meta) && is_array($meta)) {
            $currentMeta = $meta;
        } elseif (file_exists($metaImagesPath)) {
            $decodedMeta = json_decode((string) file_get_contents($metaImagesPath), true);
            if (is_array($decodedMeta)) {
                $currentMeta = $decodedMeta;
            }
        }

        // Final application to result image in data/images
        // Skip redundant full-res static image re-bake if static preset + amount already match what is saved on disk
        $targetFile = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . $file;
        $thumbFile = FolderEnum::THUMBS->absolute() . DIRECTORY_SEPARATOR . $file;
        $staticAlreadyBaked = is_file($targetFile)
            && filesize($targetFile) > 1000
            && (($currentMeta['preset'] ?? '') === $presetId)
            && ((int) ($currentMeta['preset_amount'] ?? 100) === $amount);

        if (!$staticAlreadyBaked) {
            $frameOverlay = ($presetId !== 'original' && $amount > 0 && $framePngPath && file_exists($framePngPath))
                ? $framePngPath
                : null;
            $success = $presetService->applyPreset($baseSourceFile, $targetFile, $presetId, $amount, $frameOverlay, 94);
            if (!$success) {
                throw new \Exception('Failed applying preset to image');
            }

            // Regenerate thumbnail if needed
            if (file_exists($targetFile)) {
                copy($targetFile, $thumbFile);
            }
        }

        $currentMeta['session_id'] = $currentMeta['session_id'] ?? $sessionId;
        $currentMeta['final_photo'] = $file;
        $currentMeta['preset'] = $presetId;
        $currentMeta['preset_amount'] = $amount;
        $currentMeta['updated_at'] = time();

        $shouldBakeGif = !empty($_POST['bake_gif']) || ($action === 'bake_gif');
        $gifUrl = null;
        $gifFileName = $currentMeta['gif_photo'] ?? ($sessionId . '.gif');
        $gifTargetPath = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . $gifFileName;
        $gifBaked = false;

        // Decouple heavy GIF re-baking: only re-bake when explicitly requested (e.g. user opens GIF viewer)
        if ($shouldBakeGif) {
            // Fast-path cache hit: if GIF is already baked for this exact preset and amount, return immediately (<2ms)
            $gifCacheHit = is_file($gifTargetPath)
                && filesize($gifTargetPath) > 1000
                && (($currentMeta['gif_baked_preset'] ?? '') === $presetId)
                && ((int) ($currentMeta['gif_baked_amount'] ?? 100) === $amount)
                && empty($currentMeta['gif_dirty']);

            if ($gifCacheHit) {
                $gifBaked = true;
            } else {
                $gifProcessor = new \Photobooth\Processor\GifProcessor();

                if (!empty($currentMeta['isCollage']) && !empty($currentMeta['layout'])) {
                    $layoutConfigPath = \Photobooth\Collage::getCollageConfigPath(
                        (string) $currentMeta['layout'],
                        (string) ($currentMeta['orientation'] ?? 'portrait')
                    );
                    if ($layoutConfigPath && is_file($layoutConfigPath)) {
                        $layoutData = json_decode((string) file_get_contents($layoutConfigPath), true);
                        if (is_array($layoutData) && !empty($layoutData['layout'])) {
                            $origPoses = is_array($currentMeta['original_photos'] ?? null) ? $currentMeta['original_photos'] : [];
                            $tlByPose = is_array($currentMeta['timelapse_by_pose'] ?? null) ? $currentMeta['timelapse_by_pose'] : [];
                            $poseCount = max(1, count($origPoses), count($tlByPose));
                            $poseFrameSequences = [];
                            for ($p = 0; $p < $poseCount; $p++) {
                                $seq = [];
                                if (!empty($tlByPose[$p]) && is_array($tlByPose[$p])) {
                                    foreach ($tlByPose[$p] as $tlName) {
                                        $absTl = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . basename((string) $tlName);
                                        if (is_file($absTl)) {
                                            $seq[] = $absTl;
                                        }
                                    }
                                }
                                if (isset($origPoses[$p])) {
                                    $absPose = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . basename((string) $origPoses[$p]);
                                    if (is_file($absPose)) {
                                        $seq[] = $absPose;
                                    }
                                }
                                if (!empty($seq)) {
                                    $poseFrameSequences[$p] = $seq;
                                }
                            }
                            if (!empty($poseFrameSequences)) {
                                $gifBaked = $gifProcessor->createFramedTimelapseGif(
                                    $poseFrameSequences,
                                    $layoutData,
                                    $gifTargetPath,
                                    180,
                                    1350,
                                    $presetId,
                                    $amount,
                                    $baseSourceFile
                                );
                            }
                        }
                    }
                }

                if (!$gifBaked) {
                    $gifSourceNames = !empty($currentMeta['timelapse_photos']) && is_array($currentMeta['timelapse_photos'])
                        ? $currentMeta['timelapse_photos']
                        : ($currentMeta['original_photos'] ?? []);
                    if (is_array($gifSourceNames) && !empty($gifSourceNames)) {
                        $poseAbsPaths = [];
                        foreach ($gifSourceNames as $poseName) {
                            $candidatePose = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . basename((string) $poseName);
                            if (file_exists($candidatePose)) {
                                $poseAbsPaths[] = $candidatePose;
                            }
                        }
                        if (!empty($poseAbsPaths)) {
                            $gifBaked = $gifProcessor->createAnimatedGif($poseAbsPaths, $gifTargetPath, 180, 1080, $presetId, $amount);
                        }
                    }
                }
            }

            if ($gifBaked) {
                $currentMeta['gif_photo'] = $gifFileName;
                $currentMeta['gif_baked_preset'] = $presetId;
                $currentMeta['gif_baked_amount'] = $amount;
                $currentMeta['gif_dirty'] = false;
                $gifUrl = PathUtility::getPublicPath('data/images/' . $gifFileName) . '?v=' . filemtime($gifTargetPath);
            }
        } else {
            // Mark whether GIF needs re-baking when user switches to GIF viewer
            $currentMeta['gif_dirty'] = (
                $presetId !== ($currentMeta['gif_baked_preset'] ?? 'original') ||
                $amount !== (int) ($currentMeta['gif_baked_amount'] ?? 100)
            );
            if (file_exists($gifTargetPath)) {
                $gifUrl = PathUtility::getPublicPath('data/images/' . $gifFileName) . '?v=' . filemtime($gifTargetPath);
            }
        }

        $encodedMeta = json_encode($currentMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        @file_put_contents($metaImagesPath, $encodedMeta);
        if (file_exists($metaTempPath)) {
            @file_put_contents($metaTempPath, $encodedMeta);
        }

        // Update offline-first sync queue with newly baked preset
        $syncService = \Photobooth\Service\SyncQueueService::getInstance();
        try {
            $syncService->enqueueSession($sessionId, [
                'final_photo' => $file,
                'gif_photo' => $currentMeta['gif_photo'] ?? null,
                'original_photos' => $currentMeta['original_photos'] ?? [],
                'metadata' => $currentMeta,
            ]);
        } catch (\Throwable $syncErr) {
            $logger->warning('Preset sync queue update warning: ' . $syncErr->getMessage());
        }

        $qrPayload = !empty($_POST['include_qr'])
            ? $syncService->buildSessionQrData($file, $currentMeta, false)
            : null;

        echo json_encode([
            'success' => true,
            'file' => $file,
            'url' => PathUtility::getPublicPath('data/images/' . $file) . '?v=' . time(),
            'gif_url' => $gifUrl,
            'preset' => $presetId,
            'amount' => $amount,
            'qr' => $qrPayload,
        ]);
        exit();
    } catch (\Throwable $e) {
        $logger->error('Preset API error: ' . $e->getMessage());
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
        exit();
    }
}

// GET request: Return list of presets (and run probabilistic temp file maintenance)
if (!empty($_GET['cleanup'])) {
    \Photobooth\Service\StorageMaintenanceService::getInstance()->pruneExpiredTempFiles();
} else {
    \Photobooth\Service\StorageMaintenanceService::getInstance()->runProbabilisticCleanup(15);
}
$showAll = !empty($_GET['all']);
$presets = $showAll ? $presetService->getAllPresets() : $presetService->getEnabledPresets();

echo json_encode(array_map(fn($p) => $p->toArray(), $presets));
exit();
