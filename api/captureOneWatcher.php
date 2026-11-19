<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/../vendor/autoload.php';

if (!function_exists('checkCsrfOrFail')) {
    function checkCsrfOrFail(array $source = [], string $key = 'csrf'): void
    {
    }
}

require_once __DIR__ . '/../lib/boot.php';

use Photobooth\Service\CaptureOneIntegrationService;
use Photobooth\Service\ConfigurationService;
use Photobooth\Service\LoggerService;
use Photobooth\Utility\PathUtility;

$logger = LoggerService::getInstance()->getLogger('c1-watcher');
$logger->info('Capture One background watcher started.');

$pidFile = PathUtility::getAbsolutePath('var/run/capture_one_watcher.pid');
@file_put_contents($pidFile, (string) getmypid());

$runOnce = in_array('--once', $argv ?? [], true);

while (true) {
    $config = ConfigurationService::getInstance()->getConfiguration();
    $c1Service = CaptureOneIntegrationService::getInstance();

    if (empty($config['capture_one']['enabled']) && !$runOnce) {
        sleep(3);
        continue;
    }

    try {
        $scanResult = $c1Service->scanAndIngestOutputFolder();
        if ($scanResult['ingested'] > 0) {
            $logger->info('Capture One watcher ingested batch', $scanResult);
        }
    } catch (\Throwable $e) {
        $logger->error('Capture One watcher error: ' . $e->getMessage());
    }

    if ($runOnce) {
        break;
    }

    usleep(400000); // 400ms poll interval
}

