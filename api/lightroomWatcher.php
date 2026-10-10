<?php

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/../vendor/autoload.php';

// Bypass CSRF for CLI
if (!function_exists('checkCsrfOrFail')) {
    function checkCsrfOrFail($source) {}
}

require_once __DIR__ . '/../lib/boot.php';

use Photobooth\Enum\FolderEnum;
use Photobooth\Service\LoggerService;
use Photobooth\Service\ConfigurationService;

$logger = LoggerService::getInstance()->getLogger('lightroom-watcher');
$logger->debug('Lightroom watcher started.');

$exportDir = FolderEnum::LR_EXPORT->absolute();
$tmpDir = FolderEnum::TEMP->absolute();
$internalApi = __DIR__ . '/applyEffectsInternal.php';

// Write PID file so ProcessService can track and kill us
$pidFile = \Photobooth\Utility\PathUtility::getAbsolutePath('var/run/lightroom_watcher.pid');
file_put_contents($pidFile, getmypid());

while (true) {
    $config = ConfigurationService::getInstance()->getConfiguration();
    
    if (empty($config['lightroom']['enabled'])) {
        sleep(5);
        continue;
    }

    $files = scandir($exportDir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        if (preg_match('/\.jpe?g$/i', $file)) {
            $filePath = $exportDir . DIRECTORY_SEPARATOR . $file;
            
            // Wait for file to be completely written by Lightroom
            $size1 = filesize($filePath);
            usleep(500000); // 0.5s
            clearstatcache(true, $filePath);
            $size2 = filesize($filePath);
            
            if ($size1 === 0 || $size1 !== $size2) {
                // Still writing, skip this iteration
                continue;
            }

            $logger->info("Lightroom Watcher found new image: $file");

            // Generate Photobooth filename
            $newFileName = \Photobooth\Image::createNewFilename($config['picture']['naming']);
            if ($config['database']['file'] != 'db') {
                $newFileName = $config['database']['file'] . '_' . $newFileName;
            }
            $tmpPath = $tmpDir . DIRECTORY_SEPARATOR . $newFileName;
            
            // Move file
            if (rename($filePath, $tmpPath)) {
                $logger->info("Moved to tmp as $newFileName. Triggering applyEffects...");
                
                // Call applyEffectsInternal.php
                // Using passthru or exec to wait for completion
                $cmd = 'php ' . escapeshellarg($internalApi) . ' ' . escapeshellarg($newFileName);
                exec($cmd, $output, $returnCode);
                
                if ($returnCode === 0) {
                    $logger->info("Successfully processed $newFileName");
                } else {
                    $logger->error("Failed to process $newFileName", ['output' => $output]);
                }
            } else {
                $logger->error("Failed to move $file to $tmpPath");
            }
        }
    }
    
    // Poll every 1 second
    sleep(1);
}
