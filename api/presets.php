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
            $success = $presetService->applyPreset($baseSourceFile, $targetFile, $presetId, $amount);

            if (!$success || !file_exists($targetFile)) {
                throw new \Exception('Failed generating preset preview');
            }

            // Re-apply pristine frame overlay to ensure frame colors, logos, and borders are unaffected
            if ($framePngPath && file_exists($framePngPath)) {
                reapplyFrameOverlay($targetFile, $framePngPath);
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

        // Final application to result image in data/images
        $targetFile = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . $file;
        $thumbFile = FolderEnum::THUMBS->absolute() . DIRECTORY_SEPARATOR . $file;

        $success = $presetService->applyPreset($baseSourceFile, $targetFile, $presetId, $amount);
        if (!$success) {
            throw new \Exception('Failed applying preset to image');
        }

        // Re-apply pristine frame overlay on baked image
        if ($framePngPath && file_exists($framePngPath)) {
            reapplyFrameOverlay($targetFile, $framePngPath);
        }

        // Regenerate thumbnail if needed
        if (file_exists($targetFile)) {
            copy($targetFile, $thumbFile);
        }

        echo json_encode([
            'success' => true,
            'file' => $file,
            'url' => PathUtility::getPublicPath('data/images/' . $file) . '?v=' . time(),
            'preset' => $presetId,
            'amount' => $amount,
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

// GET request: Return list of presets
$showAll = !empty($_GET['all']);
$presets = $showAll ? $presetService->getAllPresets() : $presetService->getEnabledPresets();

echo json_encode(array_map(fn($p) => $p->toArray(), $presets));
exit();

/**
 * Overlay transparent frame PNG over processed photo to keep frame colors and branding 100% pristine
 */
function reapplyFrameOverlay(string $targetImagePath, string $framePngPath): void
{
    $image = @imagecreatefromjpeg($targetImagePath);
    $frame = @imagecreatefrompng($framePngPath);
    if ($image && $frame) {
        imagealphablending($image, true);
        imagesavealpha($image, false);
        imagecopyresampled(
            $image,
            $frame,
            0,
            0,
            0,
            0,
            imagesx($image),
            imagesy($image),
            imagesx($frame),
            imagesy($frame)
        );
        imagejpeg($image, $targetImagePath, 100);
        imagedestroy($image);
        imagedestroy($frame);
    }
}
