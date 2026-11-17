<?php

/** @var array $config */

require_once '../lib/boot.php';

use Photobooth\Image;
use Photobooth\Collage;
use Photobooth\Rembg;
use Photobooth\Enum\FolderEnum;
use Photobooth\Enum\ImageFilterEnum;
use Photobooth\Processor\ImageProcessor;
use Photobooth\Service\DatabaseManagerService;
use Photobooth\Service\LoggerService;
use Photobooth\Service\RemoteStorageService;
use Photobooth\Utility\ImageUtility;
use Photobooth\Utility\PathUtility;

header('Content-Type: application/json');

checkCsrfOrFail($_POST);

$logger = LoggerService::getInstance()->getLogger('main');
$logger->debug(basename($_SERVER['PHP_SELF']));

$database = DatabaseManagerService::getInstance();
$remoteStorage = RemoteStorageService::getInstance();

$processor = null;

try {
    if (empty($_POST['file'])) {
        throw new \Exception('No file provided');
    }

    $vars['fileName'] = basename((string)$_POST['file']);
    if ($vars['fileName'] === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $vars['fileName'])) {
        throw new \Exception('Invalid file name provided');
    }

    if (!isset($_POST['style']) || !in_array($_POST['style'], ['photo', 'collage', 'custom', 'chroma'])) {
        throw new \Exception('Invalid or missing style parameter');
    }

    if (isset($_POST['collageLayout'])) {
        $config['collage']['layout'] = $_POST['collageLayout'];

    }

    $limitData = Collage::calculateLimit($config['collage'], $logger);
    $config['collage']['limit'] = $limitData['limit'];
    $config['collage']['placeholder'] = $limitData['placeholderEnabled'];

    $vars['style'] = $_POST['style'];

    $vars['imageFilter'] = null;
    if (!isset($_POST['filter'])) {
        $logger->debug('No filter provided.');
    } elseif (!empty($_POST['filter'])) {
        $vars['imageFilter'] = ImageFilterEnum::tryFrom($_POST['filter']);
    }

    $vars['preset'] = !empty($_POST['preset']) ? (string) $_POST['preset'] : null;
    $vars['preset_amount'] = isset($_POST['preset_amount']) ? (int) $_POST['preset_amount'] : 100;

    // Mode 1 (default) or Capture One External Provider: base grade was already baked at capture time (api/capture.php); record it in metadata
    $colorWorkflow = \Photobooth\Service\ColorWorkflowService::getInstance();
    $c1Service = \Photobooth\Service\CaptureOneIntegrationService::getInstance();
    $vars['baseGrade'] = $colorWorkflow->shouldGradeAtIngest()
        ? [
            'provider' => $c1Service->isEnabled() ? 'capture_one' : 'internal_lut',
            'preset' => $colorWorkflow->getBasePresetId(),
            'amount' => $colorWorkflow->getBaseAmount(),
            'process_recipe' => $c1Service->isEnabled() ? $c1Service->getProcessRecipe() : null,
        ]
        : null;
} catch (\Exception $e) {
    // Handle the exception
    $logger->error($e->getMessage(), $_POST);
    echo json_encode(['error' => $e->getMessage()]);
    die();
}

$vars['isCollage'] = $_POST['style'] === 'collage';
$vars['editSingleCollage'] = false;
$vars['isChroma'] = $_POST['style'] === 'chroma';

$vars['srcImages'] = [];
$vars['srcImages'][] = $vars['fileName'];

$applyEffectsPath = PathUtility::getAbsolutePath('private/api/applyEffects.php');
if (is_file($applyEffectsPath)) {
    $logger->debug('Using private/api/applyEffects.php.');

    try {
        include $applyEffectsPath;
    } catch (\Exception $e) {
        $logger->error('Error (private applyEffects): ' . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
        die();
    }
}

try {
    $imageHandler = new Image();
    $imageHandler->debugLevel = $config['dev']['loglevel'];

    $vars['tmpFile'] = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . $vars['fileName'];

    if (class_exists('Photobooth\Processor\ImageProcessor')) {
        $processor = new ImageProcessor($imageHandler, $logger, $database, $vars, $config);
    }

    $vars['sessionId'] = pathinfo($vars['fileName'], PATHINFO_FILENAME);
    $vars['gifFileName'] = null;
    $vars['savedOriginalPoses'] = [];
    $vars['savedTimelapseFrames'] = [];
    $vars['savedTimelapseByPose'] = [];
    $tlSourcePaths = [];
    $tlByPosePaths = [];

    // Load existing metadata if re-running applyEffects (e.g. from filter sidebar on Result screen)
    $existingMetaPath = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . 'meta_' . $vars['fileName'] . '.json';
    $existingMeta = [];
    if (is_file($existingMetaPath)) {
        $decodedExisting = json_decode((string) file_get_contents($existingMetaPath), true);
        if (is_array($decodedExisting)) {
            $existingMeta = $decodedExisting;
        }
    }

    // Collect fast-forward time-lapse frames recorded during 10s countdown + cheese, grouped by pose
    // (Mode 1: wait briefly for api/capture.php to finish baking the base grade into the last pose's frames)
    $tlGradeWaitUntil = microtime(true) + 8.0;
    while (microtime(true) < $tlGradeWaitUntil && !empty(glob(FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . 'tl_' . $vars['sessionId'] . '_p*.grading'))) {
        usleep(100000);
    }
    $tlTempPattern = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . 'tl_' . $vars['sessionId'] . '_p*.jpg';
    $tlTempFiles = glob($tlTempPattern) ?: [];
    if (!empty($tlTempFiles)) {
        natsort($tlTempFiles);
        $tlTempFiles = array_values($tlTempFiles);
        foreach ($tlTempFiles as $tlIdx => $tlTempPath) {
            if (is_file($tlTempPath) && filesize($tlTempPath) > 0) {
                $baseTl = basename($tlTempPath);
                $poseNum = 0;
                if (preg_match('/_p(\d+)_/', $baseTl, $m)) {
                    $poseNum = (int) $m[1];
                }
                $tlSaveName = sprintf('orig_tl_%s_p%02d_%03d.jpg', $vars['sessionId'], $poseNum, $tlIdx);
                $tlSaveDest = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . $tlSaveName;
                if (@copy($tlTempPath, $tlSaveDest)) {
                    $vars['savedTimelapseFrames'][] = $tlSaveName;
                    $vars['savedTimelapseByPose'][$poseNum][] = $tlSaveName;
                    $tlSourcePaths[] = $tlSaveDest;
                    $tlByPosePaths[$poseNum][] = $tlSaveDest;
                }
                @unlink($tlTempPath);
            }
        }
    } elseif (!empty($existingMeta['timelapse_by_pose']) && is_array($existingMeta['timelapse_by_pose'])) {
        $vars['savedTimelapseFrames'] = $existingMeta['timelapse_photos'] ?? [];
        $vars['savedTimelapseByPose'] = $existingMeta['timelapse_by_pose'];
        foreach ($vars['savedTimelapseByPose'] as $pNum => $names) {
            if (is_array($names)) {
                foreach ($names as $n) {
                    $absN = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . basename((string) $n);
                    if (is_file($absN)) {
                        $tlByPosePaths[(int) $pNum][] = $absN;
                        $tlSourcePaths[] = $absN;
                    }
                }
            }
        }
    }

    if ($vars['isCollage']) {
        [$vars['collageSrcImagePaths'], $vars['srcImages']] = Collage::getCollageFiles($config['collage'], $vars['tmpFile'], $vars['fileName'], $vars['srcImages']);

        // Preserve raw pose files (or restore them if re-running filter on Result screen after unlink)
        $vars['savedRawUngradedPoses'] = [];
        foreach ($vars['collageSrcImagePaths'] as $poseIdx => $posePath) {
            // If Capture One is enabled and this sub-file wasn't processed yet at capture.php, process it BEFORE Collage::createCollage
            $rawUngradedTmp = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . sprintf('raw_ungraded_%s_p%02d.jpg', $vars['sessionId'], $poseIdx);
            if ($c1Service->isEnabled() && empty($_POST['skip_capture_one']) && is_string($posePath) && file_exists($posePath) && !is_file($rawUngradedTmp)) {
                $c1Service->processCapturedPose($posePath, (string) $vars['sessionId'], (int) $poseIdx);
            }
            if (is_file($rawUngradedTmp)) {
                $rawSaveName = 'raw_pose_' . ($poseIdx + 1) . '_' . $vars['fileName'];
                $rawSaveDest = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . $rawSaveName;
                if (@copy($rawUngradedTmp, $rawSaveDest)) {
                    $vars['savedRawUngradedPoses'][] = $rawSaveName;
                }
            }

            $poseSaveName = 'orig_pose_' . ($poseIdx + 1) . '_' . $vars['fileName'];
            $poseSaveDest = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . $poseSaveName;
            if (is_string($posePath) && file_exists($posePath)) {
                if (@copy($posePath, $poseSaveDest)) {
                    $vars['savedOriginalPoses'][] = $poseSaveName;
                }
            } elseif (file_exists($poseSaveDest)) {
                // Restore sub-file to data/tmp so Collage::createCollage succeeds on filter re-application
                @copy($poseSaveDest, $posePath);
                $vars['savedOriginalPoses'][] = $poseSaveName;
            }
        }

        if ($processor !== null && $processor instanceof ImageProcessor && method_exists($processor, 'preCollageProcessing')) {
            [$imageHandler, $vars, $config] = $processor->preCollageProcessing($imageHandler, $vars, $config);
        }
        if (!Collage::createCollage($config, $vars['collageSrcImagePaths'], $vars['tmpFile'], $vars['imageFilter'])) {
            throw new \Exception('Error creating collage image.');
        }
        if ($processor !== null && $processor instanceof ImageProcessor && method_exists($processor, 'postCollageProcessing')) {
            [$imageHandler, $vars, $config] = $processor->postCollageProcessing($imageHandler, $vars, $config);
        }

        // Load layout config for Framed Time-Lapse GIF composition
        $layoutConfigPath = Collage::getCollageConfigPath($config['collage']['layout'], $config['collage']['orientation'] ?? 'portrait');
        $framePath = null;
        $layoutData = null;
        if ($layoutConfigPath && file_exists($layoutConfigPath)) {
            $decodedLayout = json_decode((string)file_get_contents($layoutConfigPath), true);
            if (is_array($decodedLayout)) {
                $layoutData = $decodedLayout;
                if (!empty($layoutData['frame'])) {
                    $framePath = $layoutData['frame'];
                }
            }
        }

        // Build per-pose frame sequences so each slot inside the Frame animates its own sped-up 10s countdown video
        $poseFrameSequences = [];
        $poseCount = max(1, count($vars['savedOriginalPoses']));
        for ($p = 0; $p < $poseCount; $p++) {
            $seq = $tlByPosePaths[$p] ?? [];
            if (isset($vars['savedOriginalPoses'][$p])) {
                $finalPoseAbs = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . $vars['savedOriginalPoses'][$p];
                if (is_file($finalPoseAbs)) {
                    $seq[] = $finalPoseAbs;
                }
            }
            if (!empty($seq)) {
                $poseFrameSequences[$p] = $seq;
            }
        }

        // Target GIF filename (decoupled from blocking pipeline for sub-second collage completion)
        $gifFileName = $vars['sessionId'] . '.gif';
        $vars['gifFileName'] = $gifFileName;

        // Save original pristine collage and frame metadata
        $origCollageTemp = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . 'orig_' . $vars['fileName'];
        $origCollageFinal = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . 'orig_' . $vars['fileName'];
        @copy($vars['tmpFile'], $origCollageTemp);
        @copy($vars['tmpFile'], $origCollageFinal);

        // Create an optimized 1200px preview base image for ultra-fast preset filtering on Result Screen (<30ms)
        $previewBaseTemp = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . 'preview_base_' . $vars['fileName'];
        if (file_exists($vars['tmpFile'])) {
            $baseImg = @imagecreatefromjpeg($vars['tmpFile']);
            if ($baseImg instanceof \GdImage) {
                $bW = imagesx($baseImg);
                $bH = imagesy($baseImg);
                if (max($bW, $bH) > 1200) {
                    $scalePreview = 1200.0 / max($bW, $bH);
                    $pW = (int) round($bW * $scalePreview);
                    $pH = (int) round($bH * $scalePreview);
                    $scaledBase = imagescale($baseImg, $pW, $pH);
                    if ($scaledBase instanceof \GdImage) {
                        imagejpeg($scaledBase, $previewBaseTemp, 90);
                        imagedestroy($scaledBase);
                    }
                } else {
                    @copy($vars['tmpFile'], $previewBaseTemp);
                }
                imagedestroy($baseImg);
            }
        }

        $metaData = [
            'session_id' => $vars['sessionId'],
            'isCollage' => true,
            'layout' => $config['collage']['layout'],
            'orientation' => $config['collage']['orientation'] ?? 'portrait',
            'frame' => $framePath,
            'limit' => $config['collage']['limit'] ?? 1,
            'preset' => $vars['preset'] ?? 'original',
            'preset_amount' => $vars['preset_amount'] ?? 100,
            'base_grade' => $vars['baseGrade'] ?? null,
            'final_photo' => $vars['fileName'],
            'gif_photo' => $vars['gifFileName'],
            'original_photos' => $vars['savedOriginalPoses'],
            'raw_ungraded_photos' => $vars['savedRawUngradedPoses'],
            'timelapse_photos' => $vars['savedTimelapseFrames'],
            'timelapse_by_pose' => $vars['savedTimelapseByPose'],
            'created_at' => time()
        ];
        @file_put_contents(FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . 'meta_' . $vars['fileName'] . '.json', json_encode($metaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @file_put_contents(FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . 'meta_' . $vars['fileName'] . '.json', json_encode($metaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } else {
        // Restore tmpFile from orig_ if re-applying filter from Result screen
        $origSingleTemp = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . 'orig_' . $vars['fileName'];
        $origSingleFinal = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . 'orig_' . $vars['fileName'];
        if (!file_exists($vars['tmpFile']) && file_exists($origSingleFinal)) {
            @copy($origSingleFinal, $vars['tmpFile']);
        }

        // Preserve raw ungraded single pose if Capture One processed it at capture.php
        $vars['savedRawUngradedPoses'] = [];
        $rawUngradedSingleTmp = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . sprintf('raw_ungraded_%s_p00.jpg', $vars['sessionId']);
        if ($c1Service->isEnabled() && empty($_POST['skip_capture_one']) && file_exists($vars['tmpFile']) && !is_file($rawUngradedSingleTmp)) {
            $c1Service->processCapturedPose($vars['tmpFile'], (string) $vars['sessionId'], 0);
        }
        if (is_file($rawUngradedSingleTmp)) {
            $rawSingleSaveName = 'raw_pose_1_' . $vars['fileName'];
            $rawSingleSaveDest = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . $rawSingleSaveName;
            if (@copy($rawUngradedSingleTmp, $rawSingleSaveDest)) {
                $vars['savedRawUngradedPoses'][] = $rawSingleSaveName;
            }
        }

        @copy($vars['tmpFile'], $origSingleTemp);
        @copy($vars['tmpFile'], $origSingleFinal);
        $vars['savedOriginalPoses'][] = 'orig_' . $vars['fileName'];

        // Target GIF filename for single photo (decoupled for instant response)
        $gifFileName = $vars['sessionId'] . '.gif';
        $vars['gifFileName'] = $gifFileName;

        // Create an optimized 1200px preview base image for single photo mode as well
        $previewBaseTemp = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . 'preview_base_' . $vars['fileName'];
        if (file_exists($vars['tmpFile'])) {
            $baseImg = @imagecreatefromjpeg($vars['tmpFile']);
            if ($baseImg instanceof \GdImage) {
                $bW = imagesx($baseImg);
                $bH = imagesy($baseImg);
                if (max($bW, $bH) > 1200) {
                    $scalePreview = 1200.0 / max($bW, $bH);
                    $pW = (int) round($bW * $scalePreview);
                    $pH = (int) round($bH * $scalePreview);
                    $scaledBase = imagescale($baseImg, $pW, $pH);
                    if ($scaledBase instanceof \GdImage) {
                        imagejpeg($scaledBase, $previewBaseTemp, 90);
                        imagedestroy($scaledBase);
                    }
                } else {
                    @copy($vars['tmpFile'], $previewBaseTemp);
                }
                imagedestroy($baseImg);
            }
        }

        $metaData = [
            'session_id' => $vars['sessionId'],
            'isCollage' => false,
            'layout' => 'single',
            'orientation' => 'portrait',
            'frame' => $config['picture']['take_frame'] ? $config['picture']['frame'] : null,
            'limit' => 1,
            'preset' => $vars['preset'] ?? 'original',
            'preset_amount' => $vars['preset_amount'] ?? 100,
            'base_grade' => $vars['baseGrade'] ?? null,
            'final_photo' => $vars['fileName'],
            'gif_photo' => $vars['gifFileName'],
            'original_photos' => $vars['savedOriginalPoses'],
            'raw_ungraded_photos' => $vars['savedRawUngradedPoses'],
            'timelapse_photos' => $vars['savedTimelapseFrames'],
            'timelapse_by_pose' => $vars['savedTimelapseByPose'],
            'created_at' => time()
        ];
        @file_put_contents(FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . 'meta_' . $vars['fileName'] . '.json', json_encode($metaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @file_put_contents(FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . 'meta_' . $vars['fileName'] . '.json', json_encode($metaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    // Pre-generate and save the companion QR code image (qr_<sessionId>.png) alongside the photo for instant History/Gallery access
    if (isset($metaData) && is_array($metaData) && !empty($vars['sessionId'])) {
        $qrTargetUrl = \Photobooth\Service\SyncQueueService::getInstance()->resolveQrTargetUrl((string) $vars['fileName']);
        $qrSavedFile = 'qr_' . $vars['sessionId'] . '.png';
        $qrSavedPath = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . $qrSavedFile;
        try {
            $qrObj = \Photobooth\Utility\QrCodeUtility::create($qrTargetUrl);
            @file_put_contents($qrSavedPath, $qrObj->getString());
            $metaData['qr_url'] = $qrTargetUrl;
            $metaData['qr_file'] = $qrSavedFile;
            @file_put_contents(FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . 'meta_' . $vars['fileName'] . '.json', json_encode($metaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            @file_put_contents(FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . 'meta_' . $vars['fileName'] . '.json', json_encode($metaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable) {
            // QR can still be generated on demand by api/qrcode.php
        }
    }

    foreach ($vars['srcImages'] as $vars['singleImageFile']) {
        $imageHandler->imageModified = false;
        $vars['resultFile'] = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . $vars['singleImageFile'];
        $vars['keyingFile'] = FolderEnum::KEYING->absolute() . DIRECTORY_SEPARATOR . $vars['singleImageFile'];
        $vars['tmpFile'] = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . $vars['singleImageFile'];
        $vars['thumbFile'] = FolderEnum::THUMBS->absolute() . DIRECTORY_SEPARATOR . $vars['singleImageFile'];

        // Capture One External Provider (single photo fallback if not already processed at capture.php)
        $rawSingleTmp = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . sprintf('raw_ungraded_%s_p00.jpg', $vars['sessionId']);
        if ($c1Service->isEnabled() && empty($_POST['skip_capture_one']) && !$vars['isCollage'] && !is_file($rawSingleTmp) && file_exists($vars['tmpFile'])) {
            $c1Service->processCapturedPose($vars['tmpFile'], (string) $vars['sessionId'], 0);
        }

        // Legacy Lightroom Bridge Workflow (kept for backward compatibility when Capture One is disabled)
        if (!$c1Service->isEnabled() && ($config['lightroom']['enabled'] ?? false) && empty($_POST['skip_lightroom'])) {
            $lrImportFile = FolderEnum::LR_IMPORT->absolute() . DIRECTORY_SEPARATOR . $vars['singleImageFile'];
            $lrExportFile = FolderEnum::LR_EXPORT->absolute() . DIRECTORY_SEPARATOR . $vars['singleImageFile'];
            
            if (copy($vars['tmpFile'], $lrImportFile)) {
                $logger->debug('Lightroom mode: Waiting for exported file ' . $vars['singleImageFile']);
                $timeout = $config['lightroom']['poll_timeout'] ?? 60;
                $startTime = time();
                $found = false;
                
                while (time() - $startTime < $timeout) {
                    if (file_exists($lrExportFile) && filesize($lrExportFile) > 0) {
                        // Wait a tiny bit to ensure Lightroom finished writing the file
                        usleep(500000); 
                        $found = true;
                        break;
                    }
                    usleep(1000000); // Wait 1 second
                }
                
                if ($found) {
                    $logger->debug('Lightroom mode: Picked up exported file.');
                    copy($lrExportFile, $vars['tmpFile']);
                    @unlink($lrExportFile); // Clean up export folder
                    // Override any filter if we used Lightroom
                    $vars['imageFilter'] = ImageFilterEnum::PLAIN;
                } else {
                    $logger->error('Lightroom mode: Timeout waiting for exported file.');
                    if (!empty($vars['preset'])) {
                        $logger->info('Falling back to local PresetService for: ' . $vars['preset']);
                        \Photobooth\Service\PresetService::getInstance()->applyPreset(
                            $vars['tmpFile'],
                            $vars['tmpFile'],
                            $vars['preset'],
                            $vars['preset_amount'] ?? 100
                        );
                    }
                }
            } else {
                $logger->error('Lightroom mode: Failed to copy to import folder.');
                if (!empty($vars['preset'])) {
                    \Photobooth\Service\PresetService::getInstance()->applyPreset(
                        $vars['tmpFile'],
                        $vars['tmpFile'],
                        $vars['preset'],
                        $vars['preset_amount'] ?? 100
                    );
                }
            }
        } elseif (!empty($vars['preset'])) {
            // Local-first preset processing (Instant <0.3s)
            $logger->debug('Applying local preset: ' . $vars['preset'] . ' with amount ' . ($vars['preset_amount'] ?? 100));
            \Photobooth\Service\PresetService::getInstance()->applyPreset(
                $vars['tmpFile'],
                $vars['tmpFile'],
                $vars['preset'],
                $vars['preset_amount'] ?? 100
            );
        }

        if (!file_exists($vars['tmpFile'])) {

            throw new \Exception('Image doesn\'t exist.');
        }

        $imageResource = $imageHandler->createFromImage($vars['tmpFile']);
        if (!$imageResource) {
            throw new \Exception('Error creating image resource.');
        }

        if ($processor !== null && $processor instanceof ImageProcessor && method_exists($processor, 'preImageProcessing')) {
            [$imageHandler, $vars, $config, $imageResource] = $processor->preImageProcessing($imageHandler, $vars, $config, $imageResource);
        }
        if (!$vars['isChroma']) {
            if ($vars['isCollage'] && $vars['fileName'] != $vars['singleImageFile']) {
                $vars['editSingleCollage'] = true;
                $imageHandler->framePath = PathUtility::getPublicPath($config['collage']['take_frame'] === 'always' ? $config['collage']['frame'] : $config['picture']['frame']);
            } else {
                $vars['editSingleCollage'] = false;
                $imageHandler->framePath = PathUtility::getPublicPath($config['picture']['frame']);
            }

            if (!$vars['isCollage'] || $vars['editSingleCollage']) {
                $filterProcessSize = intval($config['filters']['process_size'] ?? 0);

                // only downscale if filter not plain, rembg is enabled
                $originalResource = null;
                if ($vars['imageFilter'] !== ImageFilterEnum::PLAIN || $config['rembg']['enabled']) {
                    $originalWidth    = imagesx($imageResource);
                    $originalHeight   = imagesy($imageResource);
                    $originalResource = $imageResource;

                    if ($filterProcessSize > 0 && ($originalWidth > $filterProcessSize || $originalHeight > $filterProcessSize)) {
                        $downscaledResource = $imageHandler->resizeImage($imageResource, $filterProcessSize);
                        if ($downscaledResource instanceof \GdImage) {
                            $imageResource = $downscaledResource;
                        }
                    }
                }

                // apply filter (optionally downscale first for performance)
                if ($vars['imageFilter'] !== null && $vars['imageFilter'] !== ImageFilterEnum::PLAIN) {
                    try {
                        ImageUtility::applyFilter($vars['imageFilter'], $imageResource);
                        $imageHandler->imageModified = true;
                    } catch (\Exception $e) {
                        throw new \Exception('Error applying image filter.');
                    }

                }

                if ($config['picture']['flip'] !== 'off') {
                    try {
                        if ($config['picture']['flip'] === 'flip-horizontal') {
                            imageflip($imageResource, IMG_FLIP_HORIZONTAL);
                        } elseif ($config['picture']['flip'] === 'flip-vertical') {
                            imageflip($imageResource, IMG_FLIP_VERTICAL);
                        } elseif ($config['picture']['flip'] === 'flip-both') {
                            imageflip($imageResource, IMG_FLIP_BOTH);
                        }
                        $imageHandler->imageModified = true;
                    } catch (\Exception $e) {
                        throw new \Exception('Error flipping image.');
                    }
                }

                if ((int)$config['picture']['rotation'] !== 0) {
                    $imageResource = $imageHandler->rotateResizeImage(
                        image: $imageResource,
                        degrees: (int)$config['picture']['rotation'],
                    );
                    if (!$imageResource instanceof \GdImage) {
                        throw new \Exception('Error resizing resource.');
                    }
                }

                // Apply rembg
                [$imageHandler, $imageResource] = Rembg::process($imageHandler, $vars, $config['rembg'], $imageResource);

                if ($config['picture']['polaroid_effect']) {
                    $imageHandler->polaroidRotation = $config['picture']['polaroid_rotation'];
                    $imageResource = $imageHandler->effectPolaroid($imageResource);
                    if (!$imageResource instanceof \GdImage) {
                        throw new \Exception('Error applying polaroid effect.');
                    }
                }

                if (($config['picture']['take_frame'] && !$vars['isCollage']) || ($vars['editSingleCollage'] && ($config['collage']['take_frame'] === 'always' || $config['collage']['take_frame'] !== 'always' && $config['picture']['take_frame']))) {
                    if (!$vars['isCollage'] || $config['collage']['take_frame'] !== 'always') {
                        $imageHandler->frameExtend = $config['picture']['extend_by_frame'];
                        if ($config['picture']['extend_by_frame']) {
                            $imageHandler->frameExtendLeft = $config['picture']['frame_left_percentage'];
                            $imageHandler->frameExtendRight = $config['picture']['frame_right_percentage'];
                            $imageHandler->frameExtendBottom = $config['picture']['frame_bottom_percentage'];
                            $imageHandler->frameExtendTop = $config['picture']['frame_top_percentage'];
                        }
                    } else {
                        $imageHandler->frameExtend = false;
                    }
                    $imageResource = $imageHandler->applyFrame($imageResource);
                    if (!$imageResource instanceof \GdImage) {
                        throw new \Exception('Error applying frame to image resource.');
                    }
                }

                // Maybe we want this later or configurable, will take some time to process upscale again
                // Upscale back to original size
                //                if (!empty($originalResource) && $originalResource !== $imageResource) {
                //                        $restored = $imageHandler->resizeImage($imageResource, $originalWidth, $originalHeight);
                //                        if ($restored instanceof \GdImage) {
                //                            if ($imageResource instanceof \GdImage) {
                //                                unset($imageResource);
                //                            }
                //                            $imageResource = $restored;
                //                        }
                //                }
            }
        }

        if ($processor !== null && $processor instanceof ImageProcessor && method_exists($processor, 'postImageProcessing')) {
            [$imageHandler, $vars, $config, $imageResource] = $processor->postImageProcessing($imageHandler, $vars, $config, $imageResource);
        }

        if ($config['keying']['enabled'] || $vars['isChroma']) {
            $chroma_size = intval(substr($config['keying']['size'], 0, -2));
            $chromaCopyResource = $imageHandler->resizeImage($imageResource, $chroma_size);
            if ($chromaCopyResource instanceof \GdImage) {
                $imageHandler->jpegQuality = $config['jpeg_quality']['chroma'];
                if (!$imageHandler->saveJpeg($chromaCopyResource, $vars['keyingFile'])) {
                    $imageHandler->addErrorData('Warning: Failed to save chroma image copy.');
                }
            } else {
                $imageHandler->addErrorData('Warning: Failed to resize chroma resource.');
            }
            if ($chromaCopyResource instanceof \GdImage) {
                unset($chromaCopyResource);
            }
        }

        if ($config['textonpicture']['enabled'] && (!$vars['isCollage'] && !$vars['isChroma'] || $vars['editSingleCollage'])) {
            // calculate and apply text on picture if image got downscaled before
            $scale = 1.0;
            if (isset($originalWidth) && isset($originalHeight)) {
                $currentWidth = imagesx($imageResource);
                $scale        = $currentWidth / $originalWidth;
            }

            // Cast after scaling to avoid implicit float-to-int deprecation warnings in PHP 8.4
            $imageHandler->fontSize        = (int) round($config['textonpicture']['font_size'] * $scale);
            $imageHandler->textLineSpacing = (int) round($config['textonpicture']['linespace'] * $scale);
            $imageHandler->fontLocationX   = (int) round($config['textonpicture']['locationx'] * $scale);
            $imageHandler->fontLocationY   = (int) round($config['textonpicture']['locationy'] * $scale);
            $imageHandler->fontRotation = $config['textonpicture']['rotation'];
            $imageHandler->fontColor = $config['textonpicture']['font_color'];
            $imageHandler->fontPath = $config['textonpicture']['font'];
            $imageHandler->textLine1 = $config['textonpicture']['line1'];
            $imageHandler->textLine2 = $config['textonpicture']['line2'];
            $imageHandler->textLine3 = $config['textonpicture']['line3'];
            $imageResource = $imageHandler->applyText($imageResource);
            if (!$imageResource instanceof \GdImage) {
                throw new \Exception('Error applying text to image resource.');
            }
        }

        // image scale, create thumbnail
        $thumb_size = intval(substr($config['picture']['thumb_size'], 0, -2));
        $thumbResource = $imageHandler->resizeImage($imageResource, $thumb_size);
        if ($thumbResource instanceof \GdImage) {
            $imageHandler->jpegQuality = $config['jpeg_quality']['thumb'];
            if (!$imageHandler->saveJpeg($thumbResource, $vars['thumbFile'])) {
                $imageHandler->addErrorData('Warning: Failed to create thumbnail.');
            }
        } else {
            $imageHandler->addErrorData('Warning: Failed to resize thumbnail.');
        }

        if ($thumbResource instanceof \GdImage) {
            unset($thumbResource);
        }

        $imageHandler->jpegQuality = $config['jpeg_quality']['image'];
        if ($imageHandler->imageModified || ($config['jpeg_quality']['image'] >= 0 && $config['jpeg_quality']['image'] < 100)) {
            if (!$imageHandler->saveJpeg($imageResource, $vars['resultFile'])) {
                throw new \Exception('Failed to save image.');
            }
            // preserve jpeg meta data
            if ($config['picture']['preserve_exif_data'] && $config['commands']['exiftool']) {
                try {
                    $cmd = sprintf($config['commands']['exiftool'], $vars['tmpFile'], $vars['resultFile']);
                    $cmd .= ' 2>&1'; //Redirect stderr to stdout, otherwise error messages get lost.

                    exec($cmd, $output, $returnValue);

                    if ($returnValue) {
                        $errorData = [
                            'error' => 'exiftool returned with an error code',
                            'cmd' => $cmd,
                            'returnValue' => $returnValue,
                            'output' => $output,
                        ];
                        $logger->error('exiftool returned with an error code', $errorData);
                    }
                } catch (\Exception $e) {
                    $logger->error($e->getMessage());
                }
            }
        } else {
            if (!copy($vars['tmpFile'], $vars['resultFile'])) {
                throw new \Exception('Failed to copy photo.');
            }
        }
        unset($imageResource);

        // insert into database
        if ($config['database']['enabled']) {
            if (($vars['isChroma'] && $config['keying']['show_all'] === true) || !$vars['isChroma']) {
                $database->appendContentToDB($vars['singleImageFile']);
            }
        }

        // Store images on remote storage
        if ($config['ftp']['enabled']) {
            $remoteStorage->write($remoteStorage->getStorageFolder() . '/images/' . $vars['singleImageFile'], (string) file_get_contents($vars['resultFile']));
            $remoteStorage->write($remoteStorage->getStorageFolder() . '/thumbs/' . $vars['singleImageFile'], (string) file_get_contents($vars['thumbFile']));
            if ($config['ftp']['create_webpage']) {
                $remoteStorage->createWebpage();
            }
        }

        // Change permissions
        $picture_permissions = $config['picture']['permissions'];
        if (!chmod($vars['resultFile'], (int)octdec($picture_permissions))) {
            $imageHandler->addErrorData('Warning: Failed to change picture permissions.');
        }

        if (!$config['picture']['keep_original']) {
            if (!unlink($vars['tmpFile'])) {
                $imageHandler->addErrorData('Warning: Failed to remove temporary photo.');
            }
        }

        if ($_POST['style'] === 'chroma' && $config['keying']['show_all'] === false) {
            if (!unlink($vars['resultFile'])) {
                $imageHandler->addErrorData('Warning: Failed to remove photo.');
            }
            if (!unlink($vars['thumbFile'])) {
                $imageHandler->addErrorData('Warning: Failed to remove thumbnail.');
            }
        }
    }
} catch (\Exception $e) {
    // Handle the exception
    if (isset($imageResource) && $imageResource instanceof \GdImage) {
        unset($imageResource);
    }
    if (is_array($imageHandler->errorLog) && !empty($imageHandler->errorLog)) {
        $logger->error('Error', $imageHandler->errorLog);
    }
    $logger->error($e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
    die();
}

if (is_array($imageHandler->errorLog) && !empty($imageHandler->errorLog)) {
    $logger->error('Error', $imageHandler->errorLog);
}

// Enqueue session into Offline-First Sync Queue (Stage 6)
try {
    \Photobooth\Service\SyncQueueService::getInstance()->enqueueSession($vars['sessionId'], [
        'final_photo' => $vars['fileName'],
        'gif_photo' => $vars['gifFileName'] ?? null,
        'original_photos' => $vars['savedOriginalPoses'] ?? [],
        'metadata' => $metaData ?? [],
    ]);
} catch (\Throwable $syncErr) {
    $logger->warning('SyncQueue enqueue warning: ' . $syncErr->getMessage());
}

$data = [
    'file' => $vars['fileName'],
    'images' => $vars['srcImages'],
    'gif' => $vars['gifFileName'] ?? null,
    'session_id' => $vars['sessionId'] ?? null,
];
$logger->debug('effects applied', $data);
echo json_encode($data);

// Flush HTTP response immediately so UI proceeds with zero latency,
// while asynchronous companion GIF generation & Cloudflare R2 upload runs via detached background CLI runner
if (!empty($vars['sessionId'])) {
    $runnerScript = PathUtility::getAbsolutePath('api/backgroundSyncRunner.php');
    $cmd = sprintf('php %s %s > /dev/null 2>&1 &', escapeshellarg($runnerScript), escapeshellarg((string) $vars['sessionId']));
    @exec($cmd);
}

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
exit();
