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

        $isLivePreview = !empty($_POST['preview']);
        if ($isLivePreview) {
            // Write to a temporary preview file in data/tmp
            $previewFileName = 'preview_' . $presetId . '_' . $amount . '_' . $file;
            $targetFile = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . $previewFileName;
            $success = $presetService->applyPreset($sourceFile, $targetFile, $presetId, $amount);

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

        // Final application to result image in data/images
        $targetFile = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . $file;
        $thumbFile = FolderEnum::THUMBS->absolute() . DIRECTORY_SEPARATOR . $file;

        $success = $presetService->applyPreset($sourceFile, $targetFile, $presetId, $amount);
        if (!$success) {
            throw new \Exception('Failed applying preset to image');
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
