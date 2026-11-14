<?php

declare(strict_types=1);

/**
 * Internal PHP Boot, Service & Filesystem Micro-Benchmark.
 *
 * Run inside the Docker container:
 *   docker compose exec photobooth php scripts/bench-boot.php
 *
 * Safely measures the cost of each component initialized in lib/boot.php and hot paths
 * without mutating data/db.txt, data/sync_queue.json, or data/images/.
 */

use Photobooth\Enum\FolderEnum;
use Photobooth\Service\ApplicationService;
use Photobooth\Service\AssetService;
use Photobooth\Service\ConfigurationService;
use Photobooth\Service\DatabaseManagerService;
use Photobooth\Service\ImageMetadataCacheService;
use Photobooth\Service\LanguageService;
use Photobooth\Service\LoggerService;
use Photobooth\Service\MailService;
use Photobooth\Service\PresetService;
use Photobooth\Service\PrintManagerService;
use Photobooth\Service\ProcessService;
use Photobooth\Service\RemoteStorageService;
use Photobooth\Service\SoundService;
use Photobooth\Service\SyncQueueService;
use Photobooth\Utility\PathUtility;

$tStart = microtime(true);

$measure = static function (callable $fn): array {
    $t0 = hrtime(true);
    $res = $fn();
    $elapsedMs = round((hrtime(true) - $t0) / 1_000_000, 3);
    return [$elapsedMs, $res];
};

[$autoloadMs, $_] = $measure(static function (): bool {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    return true;
});

$timings = [];
$timings['vendor_autoload_ms'] = $autoloadMs;

// Measure each singleton constructed in lib/boot.php in exact order
[$timings['ApplicationService_ms'], $appService] = $measure(static fn () => new ApplicationService());
$GLOBALS[ApplicationService::class] = $appService;

[$timings['ConfigurationService_ms'], $configService] = $measure(static fn () => new ConfigurationService());
$GLOBALS[ConfigurationService::class] = $configService;
$config = $configService->getConfiguration();

[$timings['AssetService_ms'], $assetService] = $measure(static fn () => new AssetService());
$GLOBALS[AssetService::class] = $assetService;

[$timings['LoggerService_ms'], $loggerService] = $measure(static fn () => new LoggerService());
$GLOBALS[LoggerService::class] = $loggerService;

[$timings['LanguageService_ms'], $langService] = $measure(static fn () => new LanguageService());
$GLOBALS[LanguageService::class] = $langService;

[$timings['SoundService_ms'], $soundService] = $measure(static fn () => new SoundService());
$GLOBALS[SoundService::class] = $soundService;

[$timings['PrintManagerService_ms'], $printService] = $measure(static fn () => new PrintManagerService());
$GLOBALS[PrintManagerService::class] = $printService;

[$timings['DatabaseManagerService_ms'], $dbService] = $measure(static fn () => new DatabaseManagerService());
$GLOBALS[DatabaseManagerService::class] = $dbService;

[$timings['MailService_ms'], $mailService] = $measure(static fn () => new MailService());
$GLOBALS[MailService::class] = $mailService;

[$timings['ProcessService_ms'], $processService] = $measure(static fn () => new ProcessService());
$GLOBALS[ProcessService::class] = $processService;

[$timings['RemoteStorageService_ms'], $remoteStorage] = $measure(static fn () => new RemoteStorageService());
$GLOBALS[RemoteStorageService::class] = $remoteStorage;

$bootTotalMs = round(array_sum($timings), 3);

// Measure additional hot paths
$hotPaths = [];

[$hotPaths['AutoVersionStrategy_getUrl_10x_ms'], $_] = $measure(static function () use ($assetService): bool {
    $paths = [
        'node_modules/normalize.css/normalize.css',
        'node_modules/@fortawesome/fontawesome-free/css/all.min.css',
        'node_modules/photoswipe/dist/photoswipe.css',
        'node_modules/jquery/dist/jquery.min.js',
        'resources/css/fonts.css',
        'resources/css/framework.css',
        'resources/js/tools.js',
        'resources/js/preview.js',
        'resources/js/core.js',
        'resources/js/presets-ui.js',
    ];
    foreach ($paths as $p) {
        $assetService->getUrl($p);
    }
    return true;
});

[$hotPaths['PresetService_init_ms'], $presetService] = $measure(static fn () => PresetService::getInstance());
[$hotPaths['ImageMetadataCache_load_ms'], $metaCache] = $measure(static fn () => ImageMetadataCacheService::getInstance());
[$hotPaths['SyncQueue_summary_ms'], $queueSummary] = $measure(static fn () => SyncQueueService::getInstance()->getQueueSummary());

// Simulate gallery list scan + cache lookup (read-only)
[$hotPaths['Gallery_db_and_cache_ms'], $galleryCount] = $measure(static function () use ($dbService, $metaCache, $config): int {
    $images = $config['database']['enabled']
        ? $dbService->getContentFromDB()
        : $dbService->getFilesFromDirectory();
    $count = 0;
    foreach ($images as $img) {
        $metaCache->get(PathUtility::getAbsolutePath(FolderEnum::IMAGES->value . DIRECTORY_SEPARATOR . $img));
        $metaCache->get(PathUtility::getAbsolutePath(FolderEnum::THUMBS->value . DIRECTORY_SEPARATOR . $img));
        $count++;
    }
    return $count;
});

// Measure scandir('data/images') cost (used by api/qrcode.php?filename=latest and directory scans)
$imagesDir = FolderEnum::IMAGES->absolute();
[$hotPaths['scandir_data_images_ms'], $imagesFileCount] = $measure(static function () use ($imagesDir): int {
    if (!is_dir($imagesDir)) {
        return 0;
    }
    $entries = @scandir($imagesDir) ?: [];
    return count($entries);
});

$opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;

$report = [
    'environment' => [
        'php_version' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'os' => PHP_OS_FAMILY,
        'opcache_enabled' => is_array($opcache) && !empty($opcache['opcache_enabled']),
        'opcache_cli' => (bool) ini_get('opcache.enable_cli'),
        'opcache_revalidate_freq' => (int) ini_get('opcache.revalidate_freq'),
        'realpath_cache_size' => ini_get('realpath_cache_size'),
        'imagick_loaded' => extension_loaded('imagick'),
    ],
    'boot_breakdown_ms' => $timings,
    'boot_total_ms' => $bootTotalMs,
    'hot_paths_ms' => $hotPaths,
    'data_footprint' => [
        'gallery_db_items' => $galleryCount,
        'data_images_entries' => $imagesFileCount,
        'sync_queue_total' => $queueSummary['counts']['total'] ?? 0,
    ],
    'total_script_ms' => round((microtime(true) - $tStart) * 1000, 3),
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
