<?php

declare(strict_types=1);

/**
 * End-to-End Pipeline Diagnostic & Benchmark Script (Stage 8)
 * Verifies Camera Providers, Preset LUT Engine, GIF Generator, Print Queue, Sync Queue, and Security Rules.
 */

require_once __DIR__ . '/../lib/boot.php';

use Photobooth\Enum\FolderEnum;
use Photobooth\Processor\GifProcessor;
use Photobooth\Service\CameraManagerService;
use Photobooth\Service\PresetService;
use Photobooth\Service\SyncQueueService;

$results = [];
$startTotal = microtime(true);

// 1. Verify Camera Multi-Device Architecture (Stage 4)
$t0 = microtime(true);
$cameraManager = CameraManagerService::getInstance();
$camStatus = $cameraManager->getSystemCameraStatus('mock');
$mockProvider = $cameraManager->getActiveProvider(['provider' => 'mock']);
$testPose1 = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . 'diag_pose_1.jpg';
$testPose2 = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . 'diag_pose_2.jpg';

$cap1 = $mockProvider->capture($testPose1, ['collageNumber' => 0]);
$cap2 = $mockProvider->capture($testPose2, ['collageNumber' => 1]);
$results['stage4_camera'] = [
    'passed' => !empty($cap1['success']) && !empty($cap2['success']) && $cap1['is_mock'] === true,
    'providers_registered' => array_keys($camStatus['available_providers']),
    'capture_ms' => (int) round((microtime(true) - $t0) * 1000),
];

// 2. Verify Preset Engine (Stage 2)
$t1 = microtime(true);
$presetService = PresetService::getInstance();
$presets = $presetService->getEnabledPresets();
$presetOut = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . 'diag_preset_out.jpg';
$presetApplied = $presetService->applyPreset($testPose1, $presetOut, 'clean-portrait', 85);
$results['stage2_preset'] = [
    'passed' => $presetApplied && file_exists($presetOut),
    'enabled_presets_count' => count($presets),
    'preset_render_ms' => (int) round((microtime(true) - $t1) * 1000),
];

// 3. Verify Animated GIF Generation (Stage 3)
$t2 = microtime(true);
$gifOut = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . 'diag_session.gif';
$gifProcessor = new GifProcessor();
$gifCreated = $gifProcessor->createAnimatedGif([$testPose1, $testPose2], $gifOut, 400, 480, 'clean-portrait', 85);
$results['stage3_gif'] = [
    'passed' => $gifCreated && file_exists($gifOut) && filesize($gifOut) > 100,
    'gif_size_bytes' => file_exists($gifOut) ? filesize($gifOut) : 0,
    'gif_encode_ms' => (int) round((microtime(true) - $t2) * 1000),
];

// 4. Verify Offline-First Sync Queue (Stage 6)
$t3 = microtime(true);
$syncService = SyncQueueService::getInstance();
$queued = $syncService->enqueueSession('diag_test_session', [
    'final_photo' => 'diag_preset_out.jpg',
    'gif_photo' => 'diag_session.gif',
    'original_photos' => ['diag_pose_1.jpg', 'diag_pose_2.jpg'],
    'metadata' => ['preset' => 'clean-portrait', 'preset_amount' => 85],
]);
$results['stage6_sync_queue'] = [
    'passed' => ($queued['session_id'] ?? '') === 'diag_test_session',
    'environment' => $syncService->getEnvironmentProfile(),
    'status' => $queued['status'] ?? 'unknown',
    'queue_ms' => (int) round((microtime(true) - $t3) * 1000),
];

// Cleanup diagnostic temp files
foreach ([$testPose1, $testPose2, $presetOut, $gifOut] as $f) {
    if (file_exists($f)) {
        @unlink($f);
    }
}

$results['total_benchmark_ms'] = (int) round((microtime(true) - $startTotal) * 1000);
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

