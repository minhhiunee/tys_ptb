<?php

declare(strict_types=1);

/** @var array $config */
require_once '../lib/boot.php';

use Photobooth\Service\BanquetPrintService;
use Photobooth\Service\LoggerService;
use Photobooth\Service\PrintPairService;

header('Content-Type: application/json');

$logger = LoggerService::getInstance()->getLogger('main');
$banquetService = BanquetPrintService::getInstance();
$pairService = PrintPairService::getInstance();

$action = (string) ($_REQUEST['action'] ?? 'status');

try {
    if ($action === 'status') {
        echo json_encode([
            'status' => 'ok',
            'banquet' => $banquetService->getBanquetStatus(),
            'pair' => $pairService->getStatus(),
        ]);
        exit();
    }

    if ($action === 'enqueue') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            checkCsrfOrFail($_POST);
        } else {
            checkCsrfOrFail($_GET);
        }

        $filename = basename(trim((string) ($_REQUEST['filename'] ?? '')));
        $totalCopies = max(1, (int) ($_REQUEST['copies'] ?? 1));
        $customerName = trim((string) ($_REQUEST['customer_name'] ?? ''));

        if ($filename === '') {
            throw new \Exception('Chưa có tên file ảnh để đăng ký hàng đợi in.');
        }

        // Always ensure 1 copy is registered in the 2-strip instant queue (04_print_instant)
        $pairStatus = $pairService->registerStrip($filename);

        if ($totalCopies <= 1) {
            // If user changed back to 1 copy, remove any previously enqueued banquet extra copies for this session
            $sessionId = pathinfo($filename, PATHINFO_FILENAME);
            $banquetStatus = $banquetService->removeBanquetItem($sessionId);
            echo json_encode([
                'status' => 'ok',
                'message' => 'Đã đặt 1 bản trong hàng đợi in trực tiếp (2 dải/lần).',
                'banquet' => $banquetStatus,
                'pair' => $pairService->getStatus(),
            ]);
            exit();
        }

        if ($customerName === '') {
            throw new \Exception('Vui lòng nhập Tên khách hàng (hoặc Số bàn) để đánh dấu file ảnh in thêm.');
        }

        $extraCopies = $totalCopies - 1;
        $res = $banquetService->enqueueBanquetCopies($filename, $customerName, $totalCopies, $extraCopies);
        if (empty($res['success'])) {
            throw new \Exception($res['error'] ?? 'Không thể lưu file vào thư mục chờ in tiệc.');
        }

        $item = $res['item'] ?? [];
        $banquetFile = (string) ($res['banquet_file'] ?? '');

        echo json_encode([
            'status' => 'ok',
            'message' => sprintf(
                'Đã giữ 1 bản trong hàng đợi in ngay (%d/2) và lưu %d bản in thêm vào 05_print_banquet_pending/%s!',
                $pairStatus['count'] ?? 1,
                $extraCopies,
                $banquetFile
            ),
            'item' => $item,
            'banquet_file' => $banquetFile,
            'banquet' => $res['status'] ?? $banquetService->getBanquetStatus(),
            'pair' => $pairService->getStatus(),
        ]);
        exit();
    }

    if ($action === 'remove_item') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            checkCsrfOrFail($_POST);
        } else {
            checkCsrfOrFail($_GET);
        }

        $target = (string) ($_REQUEST['target'] ?? $_REQUEST['id'] ?? $_REQUEST['filename'] ?? '');
        $banquetStatus = $banquetService->removeBanquetItem($target);

        echo json_encode([
            'status' => 'ok',
            'message' => 'Đã xóa mục in thêm khỏi hàng đợi in tiệc.',
            'banquet' => $banquetStatus,
            'pair' => $pairService->getStatus(),
        ]);
        exit();
    }

    if ($action === 'print_batch') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            checkCsrfOrFail($_POST);
        } else {
            checkCsrfOrFail($_GET);
        }

        $oddStripMode = (string) ($_REQUEST['odd_mode'] ?? 'duplicate');
        if (!in_array($oddStripMode, ['duplicate', 'wait'], true)) {
            $oddStripMode = 'duplicate';
        }
        $maxSheets = max(0, (int) ($_REQUEST['max_sheets'] ?? 0));
        $onlyId = isset($_REQUEST['only_id']) && trim((string) $_REQUEST['only_id']) !== ''
            ? trim((string) $_REQUEST['only_id'])
            : null;

        $batchRes = $banquetService->processBanquetPrintBatch($config, $oddStripMode, $maxSheets, $onlyId);
        if (empty($batchRes['success'])) {
            echo json_encode([
                'status' => 'error',
                'error' => $batchRes['error'] ?? 'Không thể thực hiện ghép in hàng đợi tiệc.',
                'banquet' => $batchRes['status'] ?? $banquetService->getBanquetStatus(),
                'pair' => $pairService->getStatus(),
            ]);
            exit();
        }

        $sheetsCount = (int) ($batchRes['sheets_printed_count'] ?? 0);
        $movedCount = count($batchRes['moved_to_printed'] ?? []);

        echo json_encode([
            'status' => 'ok',
            'message' => sprintf(
                'Đã ghép tuần tự và gửi in %d tờ (4x6" Canon CP1500). Đã chuyển %d file hoàn tất sang thư mục 06_printed!',
                $sheetsCount,
                $movedCount
            ),
            'sheets_printed_count' => $sheetsCount,
            'printed_sheets' => $batchRes['printed_sheets'] ?? [],
            'moved_to_printed' => $batchRes['moved_to_printed'] ?? [],
            'banquet' => $batchRes['status'] ?? $banquetService->getBanquetStatus(),
            'pair' => $pairService->getStatus(),
        ]);
        exit();
    }

    throw new \Exception('Unknown action: ' . $action);
} catch (\Throwable $e) {
    $logger->error('banquetPrint API error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'error' => $e->getMessage(),
        'banquet' => $banquetService->getBanquetStatus(),
    ]);
    exit();
}
