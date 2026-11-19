<?php

/** @var array $config */

require_once '../lib/boot.php';

use Photobooth\Enum\FolderEnum;
use Photobooth\Image;
use Photobooth\Service\LoggerService;
use Photobooth\Service\PrintManagerService;
use Photobooth\Service\PrintPairService;
use Photobooth\Service\RemoteStorageService;
use Photobooth\Utility\PathUtility;

header('Content-Type: application/json');

$logger = LoggerService::getInstance()->getLogger('main');
$logger->debug(basename($_SERVER['PHP_SELF']));
$csrfKey         = 'csrf';
$csrfToken       = $_SESSION[$csrfKey] ?? '';
$rateLimitWindow = 60;
$rateLimitMax    = 10;
$linecount = 0;
$data = [];

try {
    $incomingToken = $_GET[$csrfKey] ?? '';
    if (!hash_equals((string)$csrfToken, (string)$incomingToken)) {
        throw new \Exception('Invalid CSRF token');
    }

    // Simple per-session rate limit for print requests
    $now = time();
    if (!isset($_SESSION['print']) || !is_array($_SESSION['print'])) {
        $_SESSION['print'] = ['count' => 0, 'window' => $now];
    }
    if (($now - ($_SESSION['print']['window'] ?? 0)) > $rateLimitWindow) {
        $_SESSION['print'] = ['count' => 0, 'window' => $now];
    }
    if (($_SESSION['print']['count'] ?? 0) >= $rateLimitMax) {
        throw new \Exception('Rate limit exceeded, please wait a moment and retry');
    }

    if (empty($_GET['filename'])) {
        throw new \Exception('No file provided!');
    }

    $printManager = PrintManagerService::getInstance();
    if ($printManager->isPrintLocked()) {
        throw new \Exception($config['print']['limit_msg']);
    }

    $imageHandler = new Image();
    $imageHandler->debugLevel = $config['dev']['loglevel'];
    $vars['randomName'] = $imageHandler->createNewFilename('random');
    $vars['fileName'] = basename($_GET['filename']);
    if ($vars['fileName'] === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $vars['fileName'])) {
        throw new \Exception('Invalid filename provided.');
    }
    if (str_starts_with($vars['fileName'], 'preview_') || str_starts_with($vars['fileName'], 'orig_')) {
        throw new \Exception('Cannot print temporary preview or raw unframed original file.');
    }
    $vars['copies'] = max(1, (int) ($_GET['copies'] ?? 1));
    $vars['uniqueName'] = substr($vars['fileName'], 0, -4) . '-' . $vars['randomName'];
    $vars['sourceFile'] = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . $vars['fileName'];
    $vars['printFile'] = FolderEnum::PRINT->absolute() . DIRECTORY_SEPARATOR . $vars['uniqueName'];

    // exit with error if file does not exist
    if (!file_exists($vars['sourceFile'])) {
        throw new \Exception('File ' . $vars['fileName'] . ' not found.');
    }

    if ($config['print']['limit'] > 0) {
        $linecount = $printManager->getPrintCountFromDB();
        $linecount = $linecount ? $linecount : 0;

        $limit = $config['print']['limit'];
        $newCount = $linecount + $vars['copies'];

        $nextThreshold = ceil($linecount / $limit) * $limit;
        if ($nextThreshold == 0) {
            $nextThreshold = $limit;
        }

        if ($newCount > $nextThreshold) {
            throw new \Exception('Unable to print ' . $vars['copies'] . ' copies');
        }
    }

    // record successful validation for rate limiting
    $_SESSION['print']['count'] = ($_SESSION['print']['count'] ?? 0) + 1;
} catch (\Exception $e) {
    // Handle the exception
    $data = [
        'status' => 'error',
        'error' => $e->getMessage(),
    ];

    $logger->error($e->getMessage());
    echo json_encode($data);
    die();
}

$privatePrintApi = PathUtility::getAbsolutePath('private/api/print.php');
if (is_file($privatePrintApi)) {
    $logger->debug('Using private/api/print.php.');

    try {
        include $privatePrintApi;
    } catch (\Exception $e) {
        $logger->error('Error (private print API): ' . $e->getMessage());
        $data = [
            'status' => 'error',
            'error' => $e->getMessage(),
        ];
        echo json_encode($data);
        die();
    }
}

$pairService = PrintPairService::getInstance();
$banquetService = \Photobooth\Service\BanquetPrintService::getInstance();

$pairService->registerStrip($vars['fileName']);

$banquetEnqueued = null;
$totalRequestedCopies = (int) $vars['copies'];
$customerName = trim((string) ($_REQUEST['customer_name'] ?? ''));

// Split Print Queue mechanism:
// When guest requests N > 1 copies (e.g. 4 copies):
//   - 1 copy is placed in the 2-strip instant queue (04_print_instant) for immediate printing
//   - (N - 1) copies are renamed with customer name + copy count ({session}_{Name}_{N-1}x.jpg)
//     and saved in 05_print_banquet_pending to be paired and printed during banquet/idle time.
if ($totalRequestedCopies > 1) {
    $extraCopies = $totalRequestedCopies - 1;
    $banquetRes = $banquetService->enqueueBanquetCopies(
        $vars['fileName'],
        $customerName !== '' ? $customerName : 'KhachHang',
        $totalRequestedCopies,
        $extraCopies
    );
    if (!empty($banquetRes['success'])) {
        $banquetEnqueued = $banquetRes['item'] ?? null;
        $data['banquet_enqueued'] = $banquetEnqueued;
        $data['banquet_file'] = $banquetRes['banquet_file'] ?? '';
    }
    // Only 1 copy is printed immediately in the instant pair queue
    $vars['copies'] = 1;
}

$pairStatus = $pairService->getStatus();
$splitOnly = !empty($_REQUEST['split_only']);

if ($splitOnly || !$pairStatus['can_print']) {
    echo json_encode([
        'status' => $banquetEnqueued !== null ? 'queued_split' : 'waiting_pair',
        'message' => $banquetEnqueued !== null
            ? sprintf(
                'Đã đưa 1 bản vào hàng đợi in ngay (%d/2) và lưu %d bản in thêm của [%s] vào thư mục chờ in tiệc (%s)!',
                $pairStatus['count'],
                (int) ($banquetEnqueued['remaining_copies'] ?? 1),
                (string) ($banquetEnqueued['customer_name'] ?? ''),
                (string) ($banquetEnqueued['banquet_file'] ?? '')
            )
            : $pairStatus['message'],
        'error' => $banquetEnqueued === null ? $pairStatus['message'] : null,
        'banquet_enqueued' => $banquetEnqueued,
        'pair' => $pairStatus,
    ]);
    die();
}

$composeRes = $pairService->composePairForPrint($vars['printFile'], 100);
if (empty($composeRes['success'])) {
    $errMsg = $composeRes['error'] ?? 'Không thể ghép 2 dải ảnh PTB4 để in.';
    $logger->error($errMsg);
    echo json_encode([
        'status' => 'error',
        'error' => $errMsg,
        'pair' => $pairStatus,
    ]);
    die();
}
$data['merged_info'] = $composeRes;

// print image
$status = 'ok';

if ($config['print']['max_multi'] > 1) {
    $cmd = sprintf(
        $config['commands']['print'],
        (int) $vars['copies'],
        escapeshellarg($vars['printFile'])
    );
} else {
    $cmd = sprintf(
        $config['commands']['print'],
        escapeshellarg($vars['printFile'])
    );
}
$logger->info($cmd);
$cmd .= ' 2>&1'; //Redirect stderr to stdout, otherwise error messages get lost.

// Create universal print job metadata file for external Windows Spooler / CUPS queue
$jobFile = FolderEnum::PRINT->absolute() . DIRECTORY_SEPARATOR . $vars['uniqueName'] . '.job.json';
$imgDimensions = @getimagesize($vars['printFile']);
$detectedOrientation = ($imgDimensions && $imgDimensions[0] > $imgDimensions[1]) ? 'landscape' : 'portrait';
$jobData = [
    'id' => $vars['uniqueName'],
    'filename' => $vars['fileName'],
    'printFile' => $vars['printFile'],
    'copies' => (int) $vars['copies'],
    'paper_size' => (string) ($_GET['paper_size'] ?? $config['print']['paper_size'] ?? '4x6'),
    'orientation' => (string) ($_GET['orientation'] ?? $config['print']['orientation'] ?? $detectedOrientation),
    'margin' => (string) ($_GET['margin'] ?? $config['print']['margin'] ?? 'borderless'),
    'timestamp' => time(),
    'status' => 'pending',
];
@file_put_contents($jobFile, json_encode($jobData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$data['job_id'] = $vars['uniqueName'];

exec($cmd, $output, $returnValue);

if ($returnValue !== 0) {
    $error = 'Print failed.';
    switch ($returnValue) {
        case 1:
            $status = 'error';
            $error = 'General error. Check printer status or file path.';
            break;
        case 2:
            $status = 'error';
            $error = 'Misuse of command. Possibly wrong syntax or options.';
            break;
        case 126:
            $status = 'error';
            $error = 'Command invoked cannot execute. Check permissions.';
            break;
        case 127:
            // lp not installed in container/Windows: Job is queued to Windows Spooler
            $status = 'ok';
            $data['spooled'] = true;
            $data['message'] = 'Lệnh in đã được chuyển vào hàng đợi máy in Windows';
            $logger->info("Print command 'lp' not found in container; job safely queued to Windows Spooler ({$vars['uniqueName']}).");
            break;
        case 238:
            $status = 'queued';
            $error = 'Image added to print queue.';
            break;
        default:
            $status = 'error';
            $error = "Unknown error (exit code $returnValue).";
    }

    if ($status === 'error') {
        $logger->error($error);
        $data['error'] = $error;
    }
}

$outputMessage = implode("\n", (array) ($output));
if (trim($outputMessage) === '') {
    $outputMessage = '(no output)';
}

$logger->debug('Print command: ' . $cmd);
$logger->debug('Print command output: ' . $outputMessage);
$logger->debug('Return value: ' . $returnValue);

if ($status === 'ok' || $status === 'queued') {
    $pairService->clearQueue([
        'job_id' => $vars['uniqueName'],
        'left_file' => $composeRes['left_file'] ?? '',
        'right_file' => $composeRes['right_file'] ?? '',
        'print_file' => $vars['printFile'],
        'printed_at' => time(),
    ]);

    if ($vars['copies'] > 1) {
        for ($i = 1; $i <= $vars['copies']; $i++) {
            $printManager->addToPrintDb($vars['fileName'], $vars['uniqueName'] . '-' . $i);
        }
    } else {
        $printManager->addToPrintDb($vars['fileName'], $vars['uniqueName']);
    }

    if ($config['print']['limit'] > 0) {
        $linecount = $printManager->getPrintCountFromDB();
        $linecount = $linecount ? $linecount : 0;
        if ($linecount % $config['print']['limit'] == 0) {
            if ($printManager->lockPrint()) {
                $status = 'locking';
            } else {
                $logger->error('Error creating the file ' . $printManager->printLockFile);
            }
        }
        file_put_contents($printManager->printCounter, $linecount);
    }
}

$data['status'] = $status;
$data['count'] = $linecount;
$data['pair'] = $pairService->getStatus();

$logger->debug('data', $data);
echo json_encode($data);
exit();
