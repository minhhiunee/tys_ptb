<?php

/** @var array $config */

require_once '../lib/boot.php';

use Photobooth\Enum\FolderEnum;
use Photobooth\FileDelete;
use Photobooth\Service\DatabaseManagerService;
use Photobooth\Service\ImageMetadataCacheService;
use Photobooth\Service\LoggerService;
use Photobooth\Service\RemoteStorageService;

header('Content-Type: application/json');

$logger = LoggerService::getInstance()->getLogger('main');
$logger->debug(basename($_SERVER['PHP_SELF']));

$remoteStorage = RemoteStorageService::getInstance();

try {
    if (empty($_POST['file'])) {
        throw new \Exception('No file provided');
    }
    $file = basename((string)$_POST['file']);
    if ($file === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $file)) {
        throw new \Exception('Invalid file name provided');
    }
} catch (\Exception $e) {
    // Handle the exception
    $logger->error($e->getMessage(), $_POST);
    echo json_encode(['error' => $e->getMessage()]);
    die();
}

$fileBaseName = pathinfo($file, PATHINFO_FILENAME);
$filesToDelete = [$file];
$paths = [
    FolderEnum::IMAGES->absolute(),
    FolderEnum::THUMBS->absolute(),
    FolderEnum::KEYING->absolute(),
];

$paths[] = FolderEnum::TEMP->absolute();

// Collect possible single images belonging to the collage
// Catch any single images that match the base pattern, even if keep_single_images is off or limit changed
foreach ($paths as $path) {
    $matches = glob($path . DIRECTORY_SEPARATOR . $fileBaseName . '-*.jpg');
    if ($matches !== false) {
        foreach ($matches as $matchedFile) {
            $filesToDelete[] = basename($matchedFile);
        }
    }
}

// Sub-file (pose) specific cleanup (e.g. retake of pose 0: 20261116_111941-0.jpg)
$subSessionId = null;
$subPoseIdx = null;
if (preg_match('/^([a-zA-Z0-9_]+)-(\d+)$/', $fileBaseName, $subMatch)) {
    $subSessionId = $subMatch[1];
    $subPoseIdx = (int) $subMatch[2];
    $subPoseNum = $subPoseIdx + 1;

    // Clean up temporary raw ungraded backups
    $staleRawTemps = [
        FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . sprintf('raw_ungraded_%s_p%02d.jpg', $subSessionId, $subPoseIdx),
        FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . sprintf('raw_ungraded_%s_p%d.jpg', $subSessionId, $subPoseIdx),
    ];
    foreach ($staleRawTemps as $staleRaw) {
        if (is_file($staleRaw)) {
            @unlink($staleRaw);
        }
    }

    // Clean up saved original poses in images/
    $staleSavedPoses = [
        FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . sprintf('orig_pose_%d_%s.jpg', $subPoseNum, $subSessionId),
        FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . sprintf('raw_pose_%d_%s.jpg', $subPoseNum, $subSessionId),
    ];
    foreach ($staleSavedPoses as $staleSaved) {
        if (is_file($staleSaved)) {
            @unlink($staleSaved);
        }
    }

    // Clean up time-lapse frames recorded for this specific pose
    $tlMatches = glob(FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . sprintf('tl_%s_p%02d_*.jpg', $subSessionId, $subPoseIdx)) ?: [];
    foreach ($tlMatches as $tlFile) {
        if (is_file($tlFile)) {
            @unlink($tlFile);
        }
    }

    // Clean up Capture One integration staging & queue for this pose
    try {
        $c1Service = \Photobooth\Service\CaptureOneIntegrationService::getInstance();
        $c1JobId = sprintf('c1_%s_p%02d', $subSessionId, $subPoseIdx);
        $c1Files = [
            $c1Service->getInputFolderAbsolute() . DIRECTORY_SEPARATOR . $c1JobId . '.jpg',
            $c1Service->getInputFolderAbsolute() . DIRECTORY_SEPARATOR . $c1JobId . '.trigger.json',
            $c1Service->getInputFolderAbsolute() . DIRECTORY_SEPARATOR . $c1JobId . '.job.json',
            $c1Service->getOutputFolderAbsolute() . DIRECTORY_SEPARATOR . $c1JobId . '.jpg',
            $c1Service->getOutputFolderAbsolute() . DIRECTORY_SEPARATOR . $c1JobId . '.done.json',
        ];
        foreach ($c1Files as $c1f) {
            if (is_file($c1f)) {
                @unlink($c1f);
            }
        }
        $queue = $c1Service->loadQueueData();
        if (isset($queue['jobs'][$c1JobId])) {
            unset($queue['jobs'][$c1JobId]);
        }
        if (isset($queue['processed_hashes']) && is_array($queue['processed_hashes'])) {
            $prefix = $subSessionId . ':p' . $subPoseIdx . ':';
            foreach (array_keys($queue['processed_hashes']) as $hKey) {
                if (str_starts_with($hKey, $prefix)) {
                    unset($queue['processed_hashes'][$hKey]);
                }
            }
        }
        $c1Service->saveQueueData($queue);
    } catch (\Throwable $c1Err) {
        $logger->debug('Capture One cleanup on retake ignored: ' . $c1Err->getMessage());
    }
}

$filesToDelete = array_values(array_unique($filesToDelete));

$logData = [
    'success' => true,
    'file' => $file,
    'files' => [],
];

// Remove cached metadata for this file and its thumb, if present
ImageMetadataCacheService::getInstance()->remove(FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . $file);
ImageMetadataCacheService::getInstance()->remove(FolderEnum::THUMBS->absolute() . DIRECTORY_SEPARATOR . $file);

foreach ($filesToDelete as $fileName) {
    $delete = new FileDelete($fileName, $paths, (bool) $config['picture']['keep_original']);
    $delete->deleteFiles();
    $singleLogData = $delete->getLogData();
    $logData['files'][$fileName] = $singleLogData;
    if (!$singleLogData['success']) {
        $logData['success'] = false;
    }

    if ($config['database']['enabled']) {
        $database = DatabaseManagerService::getInstance();
        $database->deleteContentFromDB($fileName);
    }

    if ($config['ftp']['enabled'] && $config['ftp']['delete']) {
        $remoteStorage->delete($remoteStorage->getStorageFolder() . '/images/' . $fileName);
        $remoteStorage->delete($remoteStorage->getStorageFolder() . '/thumbs/' . $fileName);
    }
}

if (!$logData['success'] || $config['dev']['loglevel'] > 1) {
    $logger->debug('data', $logData);
}

// Remove from 2-strip print pair queue and banquet pending queue if present
try {
    \Photobooth\Service\PrintPairService::getInstance()->removeStrip($file);
    $sessionId = pathinfo($file, PATHINFO_FILENAME);
    \Photobooth\Service\BanquetPrintService::getInstance()->removeBanquetItem($sessionId);
    $framedFile = FolderEnum::PIPELINE_FRAMED->absolute() . DIRECTORY_SEPARATOR . $file;
    if (is_file($framedFile)) {
        @unlink($framedFile);
    }
} catch (\Throwable $e) {
    // ignore
}

$logString = json_encode($logData);
echo $logString;
exit();
