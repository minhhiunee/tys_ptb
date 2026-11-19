<?php

/** @var array $config */

require_once '../lib/boot.php';

use Photobooth\Enum\FolderEnum;
use Photobooth\Image;
use Photobooth\PhotoboothCapture;
use Photobooth\Service\LoggerService;

header('Content-Type: application/json');

checkCsrfOrFail($_POST);

$logger = LoggerService::getInstance()->getLogger('main');
$logger->debug(basename($_SERVER['PHP_SELF']));

try {
    if (!isset($_POST['style'])) {
        throw new \Exception('No style provided');
    }

    if (isset($_POST['collageLimit'])) {
        $config['collage']['limit'] = $_POST['collageLimit'];
    }

    if (!empty($_POST['file']) && (preg_match('/^[a-z0-9_]+\.jpg$/', $_POST['file']) || preg_match('/^[a-z0-9_]+\.(mp4)$/', $_POST['file']))) {
        $file = $_POST['file'];
    } else {
        $file = $_POST['style'] === 'video' ? Image::createNewFilename($config['picture']['naming'], '.mp4') : Image::createNewFilename($config['picture']['naming']);
        if ($config['database']['file'] != 'db') {
            $file = $config['database']['file'] . '_' . $file;
        }
    }

    $filename_tmp = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . $file;
    if (file_exists($filename_tmp)) {
        $random = $_POST['style'] === 'video' ? Image::createNewFilename('random', '.mp4') : Image::createNewFilename('random');
        $filename_random = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . $random;
        rename($filename_tmp, $filename_random);
    }

    $captureHandler = new PhotoboothCapture();
    $captureHandler->debugLevel = $config['dev']['loglevel'];
    $captureHandler->fileName = $file;
    $captureHandler->tmpFile = $filename_tmp;

    switch ($_POST['style']) {
        case 'photo':
            $captureHandler->style = 'image';
            break;
        case 'collage':
            if (!is_numeric($_POST['collageNumber'])) {
                throw new \Exception('No or invalid collage number provided.');
            }

            $number = $_POST['collageNumber'] + 0;

            if ($number > $config['collage']['limit']) {
                throw new \Exception('Collage consists only of ' . $config['collage']['limit'] . ' pictures');
            }

            $captureHandler->collageSubFile = substr($file, 0, -4) . '-' . $number . '.jpg';
            $captureHandler->tmpFile = substr($filename_tmp, 0, -4) . '-' . $number . '.jpg';
            $captureHandler->style = 'collage';
            $captureHandler->collageNumber = intval($number);
            $captureHandler->collageLimit = $config['collage']['limit'];
            break;
        case 'chroma':
            $captureHandler->style = 'chroma';
            break;
        case 'custom':
            $captureHandler->style = 'image';
            break;
        case 'video':
            $captureHandler->style = 'video';
            break;
        default:
            throw new \Exception('Invalid style provided.');
    }

    $hasValidCanvasImg = !empty($_POST['canvasimg'])
        && is_string($_POST['canvasimg'])
        && strpos($_POST['canvasimg'], 'data:image/') === 0
        && strlen($_POST['canvasimg']) > 5000;

    $c1Service = \Photobooth\Service\CaptureOneIntegrationService::getInstance();
    $isC1TetheredCamera = $c1Service->isEnabled() && $c1Service->getCameraMode() === 'capture_one_owns_camera';

    if ($_POST['style'] === 'video') {
        $captureHandler->captureCmd = $config['commands']['take_video'];
        $captureHandler->captureWithCmd();
    } elseif ($isC1TetheredCamera) {
        // Option A: Capture One Owns Camera (USB Tethered directly to Capture One Session).
        // Seed a placeholder from Mock/Canvas only if Capture One times out and fallback is enabled.
        $tetherSessionId = pathinfo($file, PATHINFO_FILENAME);
        $tetherPoseIdx = ($_POST['style'] === 'collage' && isset($_POST['collageNumber'])) ? (int) $_POST['collageNumber'] : 0;
        if ($hasValidCanvasImg) {
            $captureHandler->flipImage = $config['preview']['flip'];
            $captureHandler->captureCanvas($_POST['canvasimg']);
        } elseif (!file_exists($captureHandler->tmpFile)) {
            $captureHandler->captureDemo();
        }
        $c1Res = $c1Service->processCapturedPose($captureHandler->tmpFile, $tetherSessionId, $tetherPoseIdx);
        $captureHandler->providerMeta = [
            'provider' => $c1Res['provider'],
            'is_mock' => !empty($c1Res['fallback_used']),
            'capture_time_ms' => (int) round((float) $c1Res['elapsed_ms']),
        ];
    } elseif ($config['preview']['mode'] === 'device_cam' && $config['preview']['camTakesPic'] && $hasValidCanvasImg) {
        $captureHandler->flipImage = $config['preview']['flip'];
        $captureHandler->captureCanvas($_POST['canvasimg']);
    } elseif ($config['dev']['demo_images']) {
        $captureHandler->captureDemo();
    } elseif ($config['preview']['mode'] === 'device_cam' && $config['preview']['camTakesPic']) {
        if (!$hasValidCanvasImg) {
            throw new \Exception('No canvas data provided!');
        }
        $captureHandler->flipImage = $config['preview']['flip'];
        $captureHandler->captureCanvas($_POST['canvasimg']);
    } else {
        if ($_POST['style'] === 'custom') {
            $captureHandler->captureCmd = $config['commands']['take_custom'];
        } elseif ($_POST['style'] === 'collage' && !empty($config['commands']['take_collage'])) {
            $captureHandler->captureCmd = $config['commands']['take_collage'];
        } else {
            $captureHandler->captureCmd = $config['commands']['take_picture'];
        }
        $captureHandler->captureWithCmd();
    }
    // Save fast-forward time-lapse frames captured during countdown + cheese for this pose
    $savedTlPaths = [];
    if (!empty($_POST['timelapseFrames']) && is_string($_POST['timelapseFrames'])) {
        $tlSessionId = pathinfo($file, PATHINFO_FILENAME);
        $tlPoseIdx = ($_POST['style'] === 'collage' && isset($_POST['collageNumber'])) ? (int) $_POST['collageNumber'] : 0;
        $tlDir = FolderEnum::TEMP->absolute();

        // Remove any previous time-lapse frames for this specific pose (in case of retake)
        $existingTl = glob($tlDir . DIRECTORY_SEPARATOR . sprintf('tl_%s_p%02d_*.jpg', $tlSessionId, $tlPoseIdx)) ?: [];
        foreach ($existingTl as $oldTlFile) {
            @unlink($oldTlFile);
        }

        $decodedTl = json_decode($_POST['timelapseFrames'], true);
        if (is_array($decodedTl)) {
            $savedCount = 0;
            foreach ($decodedTl as $rawDataUrl) {
                if (!is_string($rawDataUrl) || !str_starts_with($rawDataUrl, 'data:image/')) {
                    continue;
                }
                $commaPos = strpos($rawDataUrl, ',');
                if ($commaPos === false) {
                    continue;
                }
                $bin = base64_decode(substr($rawDataUrl, $commaPos + 1), true);
                if ($bin === false || strlen($bin) < 200) {
                    continue;
                }
                $tlFilePath = $tlDir . DIRECTORY_SEPARATOR . sprintf('tl_%s_p%02d_%03d.jpg', $tlSessionId, $tlPoseIdx, $savedCount);
                if (@file_put_contents($tlFilePath, $bin) !== false) {
                    if (($config['preview']['flip'] ?? 'off') !== 'off') {
                        $tlIm = @imagecreatefromjpeg($tlFilePath);
                        if ($tlIm instanceof \GdImage) {
                            if ($config['preview']['flip'] === 'flip-horizontal') {
                                imageflip($tlIm, IMG_FLIP_HORIZONTAL);
                            } elseif ($config['preview']['flip'] === 'flip-vertical') {
                                imageflip($tlIm, IMG_FLIP_VERTICAL);
                            } elseif ($config['preview']['flip'] === 'flip-both') {
                                imageflip($tlIm, IMG_FLIP_BOTH);
                            }
                            imagejpeg($tlIm, $tlFilePath, 85);
                            imagedestroy($tlIm);
                        }
                    }
                    $savedTlPaths[] = $tlFilePath;
                    $savedCount++;
                    if ($savedCount >= 30) {
                        break;
                    }
                }
            }
        }
    }

    // Color workflow
    //  Mode 1 (default): bake the base color grade into this shot + its time-lapse frames right after capture,
    //                    so the result screen needs no color processing at all.
    //  Mode 2 (admin opt-in): keep shots pristine; warm presets in the background after the response.
    $colorWorkflow = \Photobooth\Service\ColorWorkflowService::getInstance();
    $deferredGradePaths = [];
    $gradeFlagFile = null;
    if (in_array($_POST['style'], ['photo', 'collage', 'custom'], true) && $colorWorkflow->shouldGradeAtIngest()) {
        // The shot itself is graded now (via Capture One or internal LUT); its time-lapse frames are graded right
        // after the response is flushed when possible, so the shutter / next countdown never waits for them.
        $tlSession = pathinfo($file, PATHINFO_FILENAME);
        $tlPose = ($_POST['style'] === 'collage' && isset($_POST['collageNumber'])) ? (int) $_POST['collageNumber'] : 0;
        $canDefer = function_exists('fastcgi_finish_request') && !empty($savedTlPaths);

        if (!$isC1TetheredCamera) {
            $shotStats = $colorWorkflow->processPrimaryCapture($captureHandler->tmpFile, $tlSession, $tlPose);
            $logger->debug('Primary capture color workflow completed', $shotStats);

            if (!empty($savedTlPaths)) {
                if ($canDefer) {
                    $deferredGradePaths = $savedTlPaths;
                    $gradeFlagFile = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . sprintf('tl_%s_p%02d.grading', $tlSession, $tlPose);
                    @file_put_contents($gradeFlagFile, (string) time());
                } else {
                    $colorWorkflow->gradeFilesInPlace($savedTlPaths);
                }
            }
        }
    }

    // Record raw capture into sequential pipeline stage 01_raw_captures
    try {
        if (is_file($captureHandler->tmpFile)) {
            $rawName = $_POST['style'] === 'collage' && !empty($captureHandler->collageSubFile)
                ? $captureHandler->collageSubFile
                : $file;
            \Photobooth\Service\BanquetPrintService::getInstance()->recordRawCapture($captureHandler->tmpFile, $rawName);
        }
    } catch (\Throwable) {
    }

    // send image to frontend
    \Photobooth\Service\StorageMaintenanceService::getInstance()->runProbabilisticCleanup(20);
    echo json_encode($captureHandler->returnData());

    if (function_exists('fastcgi_finish_request') && ($gradeFlagFile !== null || $colorWorkflow->isInteractive())) {
        fastcgi_finish_request();
        if ($gradeFlagFile !== null) {
            $colorWorkflow->gradeFilesInPlace($deferredGradePaths);
            @unlink($gradeFlagFile);
        } else {
            $colorWorkflow->warmUpPresets();
        }
    }
    exit();
} catch (\Exception $e) {
    $data = ['error' => $e->getMessage()];
    $logger->error($e->getMessage(), $data);
    echo json_encode($data);
    exit();
}
