<?php

declare(strict_types=1);

require_once '../lib/boot.php';

use Photobooth\Enum\FolderEnum;
use Photobooth\Service\LoggerService;
use Photobooth\Utility\PathUtility;

header('Content-Type: application/json');

$logger = LoggerService::getInstance()->getLogger('main');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['confirmed' => false, 'error' => 'Method Not Allowed']);
    exit();
}

// 1. Authenticate incoming kiosk request via API Key
$configuredKey = trim((string) ($config['sync']['api_key'] ?? ''));
$incomingKey = trim((string) ($_SERVER['HTTP_X_PHOTOBOOTH_API_KEY'] ?? $_POST['api_key'] ?? ''));

if ($configuredKey !== '' && !hash_equals($configuredKey, $incomingKey)) {
    $logger->warning('Unauthorized syncReceive attempt', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
    http_response_code(401);
    echo json_encode(['confirmed' => false, 'error' => 'Unauthorized API Key']);
    exit();
}

// 2. Validate session_id
$sessionId = basename(trim((string) ($_POST['session_id'] ?? '')));
if ($sessionId === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $sessionId)) {
    http_response_code(400);
    echo json_encode(['confirmed' => false, 'error' => 'Invalid session_id']);
    exit();
}

$maxFileSizeBytes = (int) ($config['sync']['max_file_size_mb'] ?? 25) * 1024 * 1024;
$allowedMimeMap = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
];

$imagesDir = FolderEnum::IMAGES->absolute();
if (!is_dir($imagesDir)) {
    @mkdir($imagesDir, 0755, true);
}

$savedFiles = [];

try {
    foreach (['final_photo', 'gif_photo'] as $field) {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $fileInfo = $_FILES[$field];
        if ($fileInfo['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException("Upload error for {$field}: code " . $fileInfo['error']);
        }

        if ($fileInfo['size'] <= 0 || $fileInfo['size'] > $maxFileSizeBytes) {
            throw new \RuntimeException("File {$field} exceeds allowed size limit.");
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string) $finfo->file($fileInfo['tmp_name']);
        if (!isset($allowedMimeMap[$detectedMime])) {
            throw new \RuntimeException("Unsupported MIME type ({$detectedMime}) for {$field}.");
        }

        $safeName = basename((string) $fileInfo['name']);
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $safeName)) {
            $safeName = $sessionId . '.' . $allowedMimeMap[$detectedMime];
        }

        $destPath = $imagesDir . DIRECTORY_SEPARATOR . $safeName;
        if (!move_uploaded_file($fileInfo['tmp_name'], $destPath)) {
            throw new \RuntimeException("Failed saving uploaded file {$safeName}");
        }

        $savedFiles[$field] = $safeName;
    }

    // Save session metadata JSON
    $rawMeta = (string) ($_POST['metadata'] ?? '{}');
    $metaArray = json_decode($rawMeta, true);
    if (!is_array($metaArray)) {
        $metaArray = [];
    }

    $metaArray['session_id'] = $sessionId;
    $metaArray['final_photo'] = $savedFiles['final_photo'] ?? ($metaArray['final_photo'] ?? ($sessionId . '.jpg'));
    $metaArray['gif_photo'] = $savedFiles['gif_photo'] ?? ($metaArray['gif_photo'] ?? null);
    $metaArray['synced_at'] = time();

    $metaFileName = 'meta_' . ($metaArray['final_photo'] ?? ($sessionId . '.jpg')) . '.json';
    file_put_contents(
        $imagesDir . DIRECTORY_SEPARATOR . $metaFileName,
        json_encode($metaArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );

    $shareFile = $metaArray['final_photo'] ?? ($sessionId . '.jpg');
    $shareUrl = PathUtility::getPublicPath('view.php?image=' . rawurlencode((string) $shareFile), true);

    echo json_encode([
        'confirmed' => true,
        'session_id' => $sessionId,
        'saved_files' => $savedFiles,
        'share_url' => $shareUrl,
    ]);
    exit();
} catch (\Throwable $e) {
    $logger->error('syncReceive error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'confirmed' => false,
        'session_id' => $sessionId,
        'error' => $e->getMessage(),
    ]);
    exit();
}

