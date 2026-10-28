<?php

declare(strict_types=1);

require_once '../lib/boot.php';

use Photobooth\Enum\FolderEnum;

header('Content-Type: application/json');

$jobId = basename(trim((string) ($_REQUEST['job_id'] ?? '')));
if ($jobId === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $jobId)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Invalid or missing job_id']);
    exit();
}

$printDir = FolderEnum::PRINT->absolute();
$pendingPath = $printDir . DIRECTORY_SEPARATOR . $jobId . '.job.json';
$donePath = $printDir . DIRECTORY_SEPARATOR . $jobId . '.job.done';
$errPath = $printDir . DIRECTORY_SEPARATOR . $jobId . '.job.error';
$missingPath = $printDir . DIRECTORY_SEPARATOR . $jobId . '.job.missing';

$action = $_REQUEST['action'] ?? 'status';

// Handle retry action
if ($action === 'retry' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrfOrFail($_POST);

    $sourceStateFile = null;
    foreach ([$errPath, $missingPath, $donePath] as $candidate) {
        if (file_exists($candidate)) {
            $sourceStateFile = $candidate;
            break;
        }
    }

    if ($sourceStateFile !== null) {
        $raw = @file_get_contents($sourceStateFile);
        $data = $raw ? json_decode($raw, true) : [];
        if (is_array($data)) {
            $data['status'] = 'pending';
            $data['retried_at'] = time();
            file_put_contents($pendingPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            @unlink($sourceStateFile);
            echo json_encode([
                'status' => 'pending',
                'job_id' => $jobId,
                'message' => 'Đã gửi lại lệnh in vào hàng đợi.',
            ]);
            exit();
        }
    }

    echo json_encode(['status' => 'error', 'error' => 'Không tìm thấy lịch sử lệnh in để thử lại.']);
    exit();
}

if (file_exists($donePath)) {
    echo json_encode([
        'status' => 'completed',
        'job_id' => $jobId,
        'message' => 'Hoàn thành in ảnh!',
    ]);
    exit();
}

if (file_exists($errPath) || file_exists($missingPath)) {
    echo json_encode([
        'status' => 'error',
        'job_id' => $jobId,
        'can_retry' => true,
        'message' => 'Lỗi kết nối máy in hoặc thiếu file in. Có thể bấm Thử lại.',
    ]);
    exit();
}

if (file_exists($pendingPath)) {
    echo json_encode([
        'status' => 'pending',
        'job_id' => $jobId,
        'message' => 'Đang trong hàng đợi máy in...',
    ]);
    exit();
}

echo json_encode([
    'status' => 'unknown',
    'job_id' => $jobId,
]);
exit();

