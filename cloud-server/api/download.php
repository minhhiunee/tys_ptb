<?php

declare(strict_types=1);

$cloudConfig = require __DIR__ . '/../config.php';

$image = basename(trim((string) ($_GET['image'] ?? '')));
$inline = isset($_GET['inline']) && $_GET['inline'] === '1';
$ext = strtolower((string) pathinfo($image, PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'gif', 'mp4'];

if (
    $image === '' ||
    !preg_match('/^[A-Za-z0-9._-]+$/', $image) ||
    str_starts_with($image, 'meta_') ||
    !in_array($ext, $allowed, true)
) {
    http_response_code(400);
    echo 'Invalid media file.';
    exit();
}

$storageDir = (string) $cloudConfig['storage_dir'];
$filePath = $storageDir . DIRECTORY_SEPARATOR . $image;

if (!is_file($filePath)) {
    http_response_code(404);
    echo 'Media not found.';
    exit();
}

$mime = match ($ext) {
    'png' => 'image/png',
    'gif' => 'image/gif',
    'mp4' => 'video/mp4',
    default => 'image/jpeg',
};

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="photobooth-' . $image . '"');
readfile($filePath);
exit();

