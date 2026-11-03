<?php

declare(strict_types=1);

$cloudConfig = require __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['confirmed' => false, 'error' => 'Method Not Allowed']);
    exit();
}

// 1. Xác thực API Key giữa máy Photobooth tại sự kiện và Website Cloud
$expectedKey = trim((string) ($cloudConfig['api_key'] ?? ''));
$incomingKey = trim((string) ($_SERVER['HTTP_X_PHOTOBOOTH_API_KEY'] ?? $_POST['api_key'] ?? ''));

if ($expectedKey !== '' && !hash_equals($expectedKey, $incomingKey)) {
    http_response_code(401);
    echo json_encode(['confirmed' => false, 'error' => 'Invalid Cloud API Key']);
    exit();
}

// 2. Kiểm tra session_id hợp lệ
$sessionId = basename(trim((string) ($_POST['session_id'] ?? '')));
if ($sessionId === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $sessionId)) {
    http_response_code(400);
    echo json_encode(['confirmed' => false, 'error' => 'Invalid session_id']);
    exit();
}

$storageDir = (string) $cloudConfig['storage_dir'];
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0755, true);
}

$maxBytes = ((int) ($cloudConfig['max_file_size_mb'] ?? 25)) * 1024 * 1024;
$allowedMime = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
];

$saved = [];

try {
    foreach (['final_photo', 'gif_photo'] as $field) {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $file = $_FILES[$field];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException("Upload error on {$field}");
        }
        if ($file['size'] <= 0 || $file['size'] > $maxBytes) {
            throw new \RuntimeException("File {$field} exceeds maximum allowed size.");
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($file['tmp_name']);
        if (!isset($allowedMime[$mime])) {
            throw new \RuntimeException("Unsupported file format: {$mime}");
        }

        $safeName = basename((string) $file['name']);
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $safeName)) {
            $safeName = $sessionId . '.' . $allowedMime[$mime];
        }

        $dest = $storageDir . DIRECTORY_SEPARATOR . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new \RuntimeException("Could not save {$safeName}");
        }
        $saved[$field] = $safeName;
    }

    $meta = json_decode((string) ($_POST['metadata'] ?? '{}'), true);
    if (!is_array($meta)) {
        $meta = [];
    }
    $meta['session_id'] = $sessionId;
    $meta['final_photo'] = $saved['final_photo'] ?? ($meta['final_photo'] ?? ($sessionId . '.jpg'));
    $meta['gif_photo'] = $saved['gif_photo'] ?? ($meta['gif_photo'] ?? null);
    $meta['synced_at'] = time();

    $metaFile = $storageDir . DIRECTORY_SEPARATOR . 'meta_' . $meta['final_photo'] . '.json';
    file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    // Tự động dọn dẹp các ảnh cũ quá hạn (Auto-cleanup)
    $expireDays = (int) ($cloudConfig['expire_days'] ?? 0);
    if ($expireDays > 0 && random_int(1, 10) === 1) {
        $cutoff = time() - ($expireDays * 86400);
        foreach (glob($storageDir . DIRECTORY_SEPARATOR . '*') ?: [] as $oldFile) {
            if (is_file($oldFile) && filemtime($oldFile) < $cutoff) {
                @unlink($oldFile);
            }
        }
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseUri = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
    $shareUrl = $scheme . '://' . $host . $baseUri . '/view.php?image=' . rawurlencode((string) $meta['final_photo']);

    echo json_encode([
        'confirmed' => true,
        'session_id' => $sessionId,
        'saved_files' => $saved,
        'share_url' => $shareUrl,
    ]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'confirmed' => false,
        'session_id' => $sessionId,
        'error' => $e->getMessage(),
    ]);
}

