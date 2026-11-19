<?php

declare(strict_types=1);

namespace Photobooth\Service;

use Photobooth\Enum\FolderEnum;
use Photobooth\Utility\FileUtility;
use Photobooth\Utility\PathUtility;

/**
 * Manages the Sequential Folder Pipeline (01_raw_captures -> 06_printed)
 * and the Banquet Extra-Print Queue (Split Print Queue: 1 instant copy + N-1 banquet copies).
 */
class BanquetPrintService
{
    private static ?self $instance = null;

    private function __construct()
    {
        $this->ensurePipelineDirectories();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Ensure all 6 sequential pipeline directories exist.
     */
    public function ensurePipelineDirectories(): void
    {
        $folders = [
            FolderEnum::PIPELINE_RAW,
            FolderEnum::PIPELINE_PENDING_FRAME,
            FolderEnum::PIPELINE_FRAMED,
            FolderEnum::PIPELINE_PRINT_INSTANT,
            FolderEnum::PIPELINE_PRINT_BANQUET,
            FolderEnum::PIPELINE_PRINTED,
        ];

        foreach ($folders as $folder) {
            $abs = $folder->absolute();
            if (!is_dir($abs)) {
                FileUtility::createDirectory($abs);
            }
        }

        $this->backfillPipelineFromExistingImages();
    }

    /**
     * One-time backfill of 01_raw_captures, 02_pending_frame, and 03_framed from existing data/images
     * if 03_framed has not been seeded yet.
     */
    private function backfillPipelineFromExistingImages(): void
    {
        $framedDir = FolderEnum::PIPELINE_FRAMED->absolute();
        $flagFile = $framedDir . DIRECTORY_SEPARATOR . '.seeded';
        if (is_file($flagFile)) {
            return;
        }

        $imagesDir = FolderEnum::IMAGES->absolute();
        if (!is_dir($imagesDir)) {
            return;
        }

        $rawDir = FolderEnum::PIPELINE_RAW->absolute();
        $pendingFrameDir = FolderEnum::PIPELINE_PENDING_FRAME->absolute();

        $allJpgs = glob($imagesDir . DIRECTORY_SEPARATOR . '*.jpg') ?: [];
        foreach ($allJpgs as $jpgPath) {
            $base = basename($jpgPath);
            if (str_starts_with($base, 'raw_pose_')) {
                $dest = $rawDir . DIRECTORY_SEPARATOR . $base;
                if (!is_file($dest)) {
                    @copy($jpgPath, $dest);
                }
            } elseif (str_starts_with($base, 'orig_pose_')) {
                $dest = $pendingFrameDir . DIRECTORY_SEPARATOR . $base;
                if (!is_file($dest)) {
                    @copy($jpgPath, $dest);
                }
            } elseif (
                !str_starts_with($base, 'orig_')
                && !str_starts_with($base, 'preview_')
                && !str_starts_with($base, 'tl_')
            ) {
                $dest = $framedDir . DIRECTORY_SEPARATOR . $base;
                if (!is_file($dest)) {
                    @copy($jpgPath, $dest);
                }
                $metaSrc = $imagesDir . DIRECTORY_SEPARATOR . 'meta_' . $base . '.json';
                $metaDest = $framedDir . DIRECTORY_SEPARATOR . 'meta_' . $base . '.json';
                if (is_file($metaSrc) && !is_file($metaDest)) {
                    @copy($metaSrc, $metaDest);
                }
            }
        }

        @file_put_contents($flagFile, (string) time());
    }

    /**
     * Stage 01: Save a copy of a raw capture from the camera into 01_raw_captures.
     */
    public function recordRawCapture(string $sourcePath, string $destFilename): bool
    {
        if (!is_file($sourcePath)) {
            return false;
        }
        $cleanName = basename(trim($destFilename));
        if ($cleanName === '') {
            return false;
        }
        $destPath = FolderEnum::PIPELINE_RAW->absolute() . DIRECTORY_SEPARATOR . $cleanName;
        return @copy($sourcePath, $destPath);
    }

    /**
     * Stage 02: Save a prepared single pose image waiting for frame composition into 02_pending_frame.
     */
    public function recordPendingFrame(string $sourcePath, string $destFilename): bool
    {
        if (!is_file($sourcePath)) {
            return false;
        }
        $cleanName = basename(trim($destFilename));
        if ($cleanName === '') {
            return false;
        }
        $destPath = FolderEnum::PIPELINE_PENDING_FRAME->absolute() . DIRECTORY_SEPARATOR . $cleanName;
        return @copy($sourcePath, $destPath);
    }

    /**
     * Stage 03: Save a completed framed photo strip into 03_framed.
     */
    public function recordFramedStrip(string $sourcePath, string $destFilename, ?array $meta = null): bool
    {
        if (!is_file($sourcePath)) {
            return false;
        }
        $cleanName = basename(trim($destFilename));
        if ($cleanName === '') {
            return false;
        }
        $framedDir = FolderEnum::PIPELINE_FRAMED->absolute();
        $destPath = $framedDir . DIRECTORY_SEPARATOR . $cleanName;
        $copied = @copy($sourcePath, $destPath);

        if (is_array($meta)) {
            $metaPath = $framedDir . DIRECTORY_SEPARATOR . 'meta_' . $cleanName . '.json';
            @file_put_contents(
                $metaPath,
                json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }

        return $copied;
    }

    /**
     * Stage 04: Synchronize the active 2-strip instant print queue with 04_print_instant directory.
     */
    public function syncInstantQueueDirectory(array $pendingItems): void
    {
        $instantDir = FolderEnum::PIPELINE_PRINT_INSTANT->absolute();
        if (!is_dir($instantDir)) {
            FileUtility::createDirectory($instantDir);
        }

        $activeFiles = [];
        $imagesDir = FolderEnum::IMAGES->absolute();
        $framedDir = FolderEnum::PIPELINE_FRAMED->absolute();

        foreach ($pendingItems as $idx => $item) {
            $fn = basename((string) ($item['filename'] ?? ''));
            if ($fn === '') {
                continue;
            }
            $activeFiles[$fn] = true;
            $destPath = $instantDir . DIRECTORY_SEPARATOR . $fn;
            if (!is_file($destPath)) {
                $srcPath = $framedDir . DIRECTORY_SEPARATOR . $fn;
                if (!is_file($srcPath)) {
                    $srcPath = $imagesDir . DIRECTORY_SEPARATOR . $fn;
                }
                if (is_file($srcPath)) {
                    @copy($srcPath, $destPath);
                }
            }
        }

        // Remove any file in 04_print_instant that is no longer in the active instant queue
        $existingFiles = glob($instantDir . DIRECTORY_SEPARATOR . '*.jpg') ?: [];
        foreach ($existingFiles as $existingPath) {
            $base = basename($existingPath);
            if (!isset($activeFiles[$base])) {
                @unlink($existingPath);
            }
        }
    }

    /**
     * Stage 06: Move printed instant queue strips and merged output into 06_printed.
     */
    public function archiveInstantPrint(string $jobId, string $leftFile, string $rightFile, string $mergedPrintPath = ''): void
    {
        $instantDir = FolderEnum::PIPELINE_PRINT_INSTANT->absolute();
        $printedDir = FolderEnum::PIPELINE_PRINTED->absolute();
        $imagesDir = FolderEnum::IMAGES->absolute();

        foreach (['left' => $leftFile, 'right' => $rightFile] as $pos => $fn) {
            $cleanFn = basename(trim($fn));
            if ($cleanFn === '') {
                continue;
            }
            $srcInstant = $instantDir . DIRECTORY_SEPARATOR . $cleanFn;
            $destPrinted = $printedDir . DIRECTORY_SEPARATOR . 'instant_' . pathinfo($cleanFn, PATHINFO_FILENAME) . '_1x_printed.jpg';

            if (is_file($srcInstant)) {
                @copy($srcInstant, $destPrinted);
                @unlink($srcInstant);
            } elseif (is_file($imagesDir . DIRECTORY_SEPARATOR . $cleanFn)) {
                @copy($imagesDir . DIRECTORY_SEPARATOR . $cleanFn, $destPrinted);
            }
        }

        if ($mergedPrintPath !== '' && is_file($mergedPrintPath)) {
            $destMerged = $printedDir . DIRECTORY_SEPARATOR . 'sheet_instant_' . basename($mergedPrintPath);
            @copy($mergedPrintPath, $destMerged);
        }

        $this->appendPrintHistoryLog([
            'type' => 'instant_pair',
            'job_id' => $jobId,
            'left_file' => $leftFile,
            'right_file' => $rightFile,
            'sheets_printed' => 1,
            'printed_at' => time(),
            'printed_at_iso' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Convert Vietnamese customer name into a safe filename token:
     * e.g. "Nguyễn Văn A - Bàn 04" -> "NguyenVanA_Ban04"
     */
    public function sanitizeCustomerName(string $rawName): string
    {
        $str = trim($rawName);
        if ($str === '') {
            return 'KhachHang';
        }

        $vietnameseMap = [
            'à' => 'a', 'á' => 'a', 'ả' => 'a', 'ã' => 'a', 'ạ' => 'a',
            'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a', 'ặ' => 'a',
            'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a', 'ậ' => 'a',
            'đ' => 'd',
            'è' => 'e', 'é' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'ẹ' => 'e',
            'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ể' => 'e', 'ễ' => 'e', 'ệ' => 'e',
            'ì' => 'i', 'í' => 'i', 'ỉ' => 'i', 'ĩ' => 'i', 'ị' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ỏ' => 'o', 'õ' => 'o', 'ọ' => 'o',
            'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ổ' => 'o', 'ỗ' => 'o', 'ộ' => 'o',
            'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ở' => 'o', 'ỡ' => 'o', 'ợ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'ủ' => 'u', 'ũ' => 'u', 'ụ' => 'u',
            'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ử' => 'u', 'ữ' => 'u', 'ự' => 'u',
            'ỳ' => 'y', 'ý' => 'y', 'ỷ' => 'y', 'ỹ' => 'y', 'ỵ' => 'y',
            'À' => 'A', 'Á' => 'A', 'Ả' => 'A', 'Ã' => 'A', 'Ạ' => 'A',
            'Ă' => 'A', 'Ằ' => 'A', 'Ắ' => 'A', 'Ẳ' => 'A', 'Ẵ' => 'A', 'Ặ' => 'A',
            'Â' => 'A', 'Ầ' => 'A', 'Ấ' => 'A', 'Ẩ' => 'A', 'Ẫ' => 'A', 'Ậ' => 'A',
            'Đ' => 'D',
            'È' => 'E', 'É' => 'E', 'Ẻ' => 'E', 'Ẽ' => 'E', 'Ẹ' => 'E',
            'Ê' => 'E', 'Ề' => 'E', 'Ế' => 'E', 'Ể' => 'E', 'Ễ' => 'E', 'Ệ' => 'E',
            'Ì' => 'I', 'Í' => 'I', 'Ỉ' => 'I', 'Ĩ' => 'I', 'Ị' => 'I',
            'Ò' => 'O', 'Ó' => 'O', 'Ỏ' => 'O', 'Õ' => 'O', 'Ọ' => 'O',
            'Ô' => 'O', 'Ồ' => 'O', 'Ố' => 'O', 'Ổ' => 'O', 'Ỗ' => 'O', 'Ộ' => 'O',
            'Ơ' => 'O', 'Ờ' => 'O', 'Ớ' => 'O', 'Ở' => 'O', 'Ỡ' => 'O', 'Ợ' => 'O',
            'Ù' => 'U', 'Ú' => 'U', 'Ủ' => 'U', 'Ũ' => 'U', 'Ụ' => 'U',
            'Ư' => 'U', 'Ừ' => 'U', 'Ứ' => 'U', 'Ử' => 'U', 'Ữ' => 'U', 'Ự' => 'U',
            'Ỳ' => 'Y', 'Ý' => 'Y', 'Ỷ' => 'Y', 'Ỹ' => 'Y', 'Ỵ' => 'Y',
        ];

        $ascii = strtr($str, $vietnameseMap);
        // Convert words to PascalCase or underscore-separated tokens
        $ascii = preg_replace('/[^A-Za-z0-9\s_-]+/', '', $ascii) ?? $ascii;
        $parts = preg_split('/[\s_-]+/', trim($ascii)) ?: [];
        $formattedParts = [];
        foreach ($parts as $part) {
            if ($part !== '') {
                $formattedParts[] = ucfirst($part);
            }
        }

        $safe = implode('', $formattedParts);
        if ($safe === '') {
            return 'KhachHang';
        }

        return substr($safe, 0, 40);
    }

    /**
     * Stage 05: Enqueue N-1 extra copies into 05_print_banquet_pending by renaming the image file
     * with customer name and remaining copy count: {session_id}_{CustomerName}_{extraCopies}x.jpg
     */
    public function enqueueBanquetCopies(
        string $sourceFilename,
        string $customerName,
        int $totalRequested,
        int $extraCopies,
        string $layout = '',
        string $frame = ''
    ): array {
        $cleanFile = basename(trim($sourceFilename));
        if ($cleanFile === '' || str_starts_with($cleanFile, 'preview_') || str_starts_with($cleanFile, 'orig_')) {
            return [
                'success' => false,
                'error' => 'File ảnh không hợp lệ để đưa vào hàng đợi in tiệc.',
            ];
        }

        $extraCopies = max(1, min(50, $extraCopies));
        $totalRequested = max($extraCopies + 1, $totalRequested);

        $framedDir = FolderEnum::PIPELINE_FRAMED->absolute();
        $imagesDir = FolderEnum::IMAGES->absolute();
        $banquetDir = FolderEnum::PIPELINE_PRINT_BANQUET->absolute();

        $srcPath = $framedDir . DIRECTORY_SEPARATOR . $cleanFile;
        if (!is_file($srcPath)) {
            $srcPath = $imagesDir . DIRECTORY_SEPARATOR . $cleanFile;
        }
        if (!is_file($srcPath)) {
            return [
                'success' => false,
                'error' => 'Không tìm thấy file ảnh đã đóng khung: ' . $cleanFile,
            ];
        }

        $sessionId = pathinfo($cleanFile, PATHINFO_FILENAME);
        $displayCustomerName = trim($customerName) !== '' ? trim($customerName) : 'Khách Tiệc';
        $safeCustomerName = $this->sanitizeCustomerName($displayCustomerName);

        // Read metadata if layout/frame not explicitly passed
        $metaPath = $imagesDir . DIRECTORY_SEPARATOR . 'meta_' . $cleanFile . '.json';
        if (($layout === '' || $frame === '') && is_file($metaPath)) {
            $meta = json_decode((string) @file_get_contents($metaPath), true);
            if (is_array($meta)) {
                $layout = $layout !== '' ? $layout : (string) ($meta['layout'] ?? 'ptb4-1');
                $frame = $frame !== '' ? $frame : (string) ($meta['frame'] ?? '');
            }
        }

        // Remove any previous pending banquet file for this same session_id + customer if updated
        $existingPattern = $banquetDir . DIRECTORY_SEPARATOR . $sessionId . '_*x.jpg';
        foreach (glob($existingPattern) ?: [] as $oldJpg) {
            @unlink($oldJpg);
            $oldJson = substr($oldJpg, 0, -4) . '.json';
            if (is_file($oldJson)) {
                @unlink($oldJson);
            }
        }

        // Naming convention: {session_id}_{CustomerName}_{extraCopies}x.jpg
        $banquetBaseName = sprintf('%s_%s_%dx', $sessionId, $safeCustomerName, $extraCopies);
        $banquetFilename = $banquetBaseName . '.jpg';
        $banquetFilePath = $banquetDir . DIRECTORY_SEPARATOR . $banquetFilename;
        $banquetMetaPath = $banquetDir . DIRECTORY_SEPARATOR . $banquetBaseName . '.json';

        if (!@copy($srcPath, $banquetFilePath)) {
            return [
                'success' => false,
                'error' => 'Không thể lưu file vào thư mục chờ in tiệc (05_print_banquet_pending).',
            ];
        }

        $itemMeta = [
            'id' => $sessionId . '_' . $safeCustomerName,
            'session_id' => $sessionId,
            'customer_name' => $displayCustomerName,
            'safe_customer_name' => $safeCustomerName,
            'total_requested' => $totalRequested,
            'instant_copies' => 1,
            'initial_extra_copies' => $extraCopies,
            'remaining_copies' => $extraCopies,
            'printed_extra_copies' => 0,
            'source_file' => $cleanFile,
            'banquet_file' => $banquetFilename,
            'layout' => $layout !== '' ? $layout : 'ptb4-1',
            'frame' => $frame,
            'created_at' => time(),
            'updated_at' => time(),
        ];

        @file_put_contents(
            $banquetMetaPath,
            json_encode($itemMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        return [
            'success' => true,
            'item' => $itemMeta,
            'banquet_file' => $banquetFilename,
            'status' => $this->getBanquetStatus(),
        ];
    }

    /**
     * Remove a pending banquet item from 05_print_banquet_pending.
     */
    public function removeBanquetItem(string $banquetFileOrId): array
    {
        $target = basename(trim($banquetFileOrId));
        if ($target === '') {
            return $this->getBanquetStatus();
        }

        $banquetDir = FolderEnum::PIPELINE_PRINT_BANQUET->absolute();
        $jpgFiles = glob($banquetDir . DIRECTORY_SEPARATOR . '*.jpg') ?: [];

        foreach ($jpgFiles as $jpgPath) {
            $base = basename($jpgPath);
            $noExt = pathinfo($base, PATHINFO_FILENAME);
            $jsonPath = $banquetDir . DIRECTORY_SEPARATOR . $noExt . '.json';
            $meta = is_file($jsonPath) ? json_decode((string) @file_get_contents($jsonPath), true) : null;
            $itemId = is_array($meta) ? (string) ($meta['id'] ?? '') : '';
            $sessionId = is_array($meta) ? (string) ($meta['session_id'] ?? '') : '';

            if ($base === $target || $noExt === $target || $itemId === $target || $sessionId === $target) {
                @unlink($jpgPath);
                if (is_file($jsonPath)) {
                    @unlink($jsonPath);
                }
            }
        }

        return $this->getBanquetStatus();
    }

    /**
     * Scan 05_print_banquet_pending and parse all pending banquet extra-print files.
     * Supports both JSON-backed entries and files renamed directly matching {session}_{Name}_{N}x.jpg.
     *
     * @return array<int, array<string, mixed>>
     */
    public function scanPendingBanquetItems(): array
    {
        $banquetDir = FolderEnum::PIPELINE_PRINT_BANQUET->absolute();
        if (!is_dir($banquetDir)) {
            return [];
        }

        $jpgFiles = glob($banquetDir . DIRECTORY_SEPARATOR . '*.jpg') ?: [];
        $items = [];

        foreach ($jpgFiles as $jpgPath) {
            $filename = basename($jpgPath);
            $noExt = pathinfo($filename, PATHINFO_FILENAME);
            $jsonPath = $banquetDir . DIRECTORY_SEPARATOR . $noExt . '.json';

            $parsedSession = $noExt;
            $parsedName = 'KhachHang';
            $parsedCopies = 1;

            // Match {session_id}_{CustomerName}_{N}x.jpg
            if (preg_match('/^(.+)_([A-Za-z0-9]+)_(\d+)x$/', $noExt, $m)) {
                $parsedSession = $m[1];
                $parsedName = $m[2];
                $parsedCopies = max(1, (int) $m[3]);
            } elseif (preg_match('/^(.+)_(\d+)x$/', $noExt, $m)) {
                $parsedSession = $m[1];
                $parsedCopies = max(1, (int) $m[2]);
            }

            $meta = [];
            if (is_file($jsonPath)) {
                $decoded = json_decode((string) @file_get_contents($jsonPath), true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }

            $remaining = isset($meta['remaining_copies']) ? max(1, (int) $meta['remaining_copies']) : $parsedCopies;
            // If file was manually renamed with a different {N}x suffix, honor the filename suffix
            if (preg_match('/_(\d+)x$/', $noExt, $mSuffix)) {
                $remaining = max(1, (int) $mSuffix[1]);
            }

            $sessionId = (string) ($meta['session_id'] ?? $parsedSession);
            $safeName = (string) ($meta['safe_customer_name'] ?? $parsedName);
            $displayName = (string) ($meta['customer_name'] ?? $safeName);
            $sourceFile = (string) ($meta['source_file'] ?? ($sessionId . '.jpg'));
            $createdAt = (int) ($meta['created_at'] ?? @filemtime($jpgPath) ?: time());

            $items[] = [
                'id' => (string) ($meta['id'] ?? ($sessionId . '_' . $safeName)),
                'session_id' => $sessionId,
                'customer_name' => $displayName,
                'safe_customer_name' => $safeName,
                'total_requested' => (int) ($meta['total_requested'] ?? ($remaining + 1)),
                'instant_copies' => (int) ($meta['instant_copies'] ?? 1),
                'initial_extra_copies' => (int) ($meta['initial_extra_copies'] ?? $remaining),
                'remaining_copies' => $remaining,
                'printed_extra_copies' => (int) ($meta['printed_extra_copies'] ?? 0),
                'source_file' => $sourceFile,
                'banquet_file' => $filename,
                'file_path' => $jpgPath,
                'json_path' => $jsonPath,
                'image_url' => PathUtility::getPublicPath(FolderEnum::PIPELINE_PRINT_BANQUET->value . '/' . $filename),
                'layout' => (string) ($meta['layout'] ?? 'ptb4-1'),
                'frame' => (string) ($meta['frame'] ?? ''),
                'created_at' => $createdAt,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            if ($a['created_at'] !== $b['created_at']) {
                return $a['created_at'] <=> $b['created_at'];
            }
            return strcmp((string) $a['banquet_file'], (string) $b['banquet_file']);
        });

        return $items;
    }

    /**
     * Get comprehensive status of the Banquet Extra-Print Queue and all 6 Sequential Pipeline folders.
     */
    public function getBanquetStatus(): array
    {
        $items = $this->scanPendingBanquetItems();
        $totalStrips = 0;
        foreach ($items as $item) {
            $totalStrips += (int) $item['remaining_copies'];
        }

        $evenPairs = intdiv($totalStrips, 2);
        $oddRemainder = $totalStrips % 2;
        $totalSheetsIfAll = (int) ceil($totalStrips / 2.0);

        return [
            'pending_customers' => count($items),
            'total_pending_strips' => $totalStrips,
            'even_pairs_count' => $evenPairs,
            'has_odd_strip' => $oddRemainder === 1,
            'total_sheets_needed' => $totalSheetsIfAll,
            'items' => $items,
            'pipeline_folders' => $this->getPipelineFoldersSummary(),
            'recent_printed' => $this->getRecentPrintedHistory(12),
        ];
    }

    /**
     * Summarize file counts and paths across the 6 sequential folders.
     */
    public function getPipelineFoldersSummary(): array
    {
        $stages = [
            [
                'stage' => '01',
                'key' => '01_raw_captures',
                'label' => '01. Ảnh Chụp Gốc (Raw Captures)',
                'folder' => FolderEnum::PIPELINE_RAW,
                'desc' => 'Ảnh chụp từ máy ảnh trước khi xử lý khung',
            ],
            [
                'stage' => '02',
                'key' => '02_pending_frame',
                'label' => '02. Ảnh Chờ Lồng Frame (Pending Frame)',
                'folder' => FolderEnum::PIPELINE_PENDING_FRAME,
                'desc' => 'Các ảnh pose đã căn chỉnh chờ ghép vào khung',
            ],
            [
                'stage' => '03',
                'key' => '03_framed',
                'label' => '03. Ảnh Đã Lồng Frame (Framed Strips)',
                'folder' => FolderEnum::PIPELINE_FRAMED,
                'desc' => 'Dải ảnh hoàn chỉnh đã ghép khung PTB4_1 / PTB4_2',
            ],
            [
                'stage' => '04',
                'key' => '04_print_instant',
                'label' => '04. Hàng Đợi In Ngay (Instant Pair Queue)',
                'folder' => FolderEnum::PIPELINE_PRINT_INSTANT,
                'desc' => 'Đợi đủ 2 dải để ghép in ngay lập tức cho khách',
            ],
            [
                'stage' => '05',
                'key' => '05_print_banquet_pending',
                'label' => '05. Chờ In Thêm Lúc Tiệc (Banquet Queue)',
                'folder' => FolderEnum::PIPELINE_PRINT_BANQUET,
                'desc' => 'Ảnh đánh dấu Tên Khách + Số Lượng ({Tên}_{N}x.jpg)',
            ],
            [
                'stage' => '06',
                'key' => '06_printed',
                'label' => '06. Ảnh Đã Được In (Printed Archive)',
                'folder' => FolderEnum::PIPELINE_PRINTED,
                'desc' => 'Ảnh đã in xong được chuyển sang đây để tránh nhầm lẫn',
            ],
        ];

        $summary = [];
        foreach ($stages as $st) {
            /** @var FolderEnum $enum */
            $enum = $st['folder'];
            $abs = $enum->absolute();
            $files = is_dir($abs) ? (glob($abs . DIRECTORY_SEPARATOR . '*.{jpg,jpeg,png,cr3,CR3}', GLOB_BRACE) ?: []) : [];
            $summary[] = [
                'stage' => $st['stage'],
                'key' => $st['key'],
                'label' => $st['label'],
                'desc' => $st['desc'],
                'relative_path' => $enum->value,
                'count' => count($files),
            ];
        }

        return $summary;
    }

    /**
     * Sequentially pair every 2 strips in 05_print_banquet_pending onto 1969x2913 Canon CP1500 sheets,
     * send them to the printer spooler, and move completed files to 06_printed.
     *
     * @param array  $config        Global photobooth configuration
     * @param string $oddStripMode  'duplicate' (pair last odd strip with itself) or 'wait' (keep 1 odd strip waiting)
     * @param int    $maxSheets     Maximum number of 4x6" sheets to print in this run (0 = print all available)
     * @param string|null $onlyId   Optional specific item ID or filename to print
     */
    public function processBanquetPrintBatch(
        array $config,
        string $oddStripMode = 'duplicate',
        int $maxSheets = 0,
        ?string $onlyId = null
    ): array {
        $items = $this->scanPendingBanquetItems();
        if ($onlyId !== null && trim($onlyId) !== '') {
            $filterTarget = trim($onlyId);
            $items = array_values(array_filter($items, static function (array $item) use ($filterTarget): bool {
                return $item['id'] === $filterTarget
                    || $item['banquet_file'] === $filterTarget
                    || $item['session_id'] === $filterTarget;
            }));
        }

        if (empty($items)) {
            return [
                'success' => false,
                'error' => 'Không có ảnh nào trong thư mục chờ in tiệc (05_print_banquet_pending).',
                'status' => $this->getBanquetStatus(),
            ];
        }

        // Expand items into sequential Strip Units
        $stripUnits = [];
        $itemsById = [];
        foreach ($items as $item) {
            $id = $item['id'];
            $itemsById[$id] = $item;
            $rem = (int) $item['remaining_copies'];
            for ($c = 1; $c <= $rem; $c++) {
                $stripUnits[] = [
                    'item_id' => $id,
                    'session_id' => $item['session_id'],
                    'customer_name' => $item['customer_name'],
                    'safe_customer_name' => $item['safe_customer_name'],
                    'banquet_file' => $item['banquet_file'],
                    'file_path' => $item['file_path'],
                    'layout' => $item['layout'],
                    'copy_index' => $c,
                    'is_bonus_duplicate' => false,
                ];
            }
        }

        $totalUnits = count($stripUnits);
        if ($totalUnits === 0) {
            return [
                'success' => false,
                'error' => 'Số lượng bản in còn lại bằng 0.',
                'status' => $this->getBanquetStatus(),
            ];
        }

        // Pair sequential strips 2 at a time
        $pairs = [];
        for ($i = 0; $i < $totalUnits; $i += 2) {
            if (isset($stripUnits[$i + 1])) {
                $pairs[] = [
                    'left' => $stripUnits[$i],
                    'right' => $stripUnits[$i + 1],
                ];
            } else {
                // 1 odd strip remaining at the end
                if ($oddStripMode === 'duplicate') {
                    $bonusRight = $stripUnits[$i];
                    $bonusRight['is_bonus_duplicate'] = true;
                    $pairs[] = [
                        'left' => $stripUnits[$i],
                        'right' => $bonusRight,
                    ];
                }
                // If $oddStripMode === 'wait', we leave the last strip unprinted in 05_print_banquet_pending
            }
        }

        if (empty($pairs)) {
            return [
                'success' => false,
                'error' => 'Hiện chỉ có 1 dải lẻ trong hàng đợi và bạn đang chọn chế độ "Chờ đủ cặp chẵn". Hãy chọn "Ghép nhân đôi dải lẻ" nếu muốn in luôn 1 dải này.',
                'status' => $this->getBanquetStatus(),
            ];
        }

        if ($maxSheets > 0 && count($pairs) > $maxSheets) {
            $pairs = array_slice($pairs, 0, $maxSheets);
        }

        $pairService = PrintPairService::getInstance();
        $printManager = PrintManagerService::getInstance();
        $logger = LoggerService::getInstance()->getLogger('main');
        $printDir = FolderEnum::PRINT->absolute();
        $printedDir = FolderEnum::PIPELINE_PRINTED->absolute();

        $consumedPerItem = [];
        $printedSheets = [];

        foreach ($pairs as $pairIdx => $pair) {
            $left = $pair['left'];
            $right = $pair['right'];

            $sheetNum = $pairIdx + 1;
            $uniqueJobId = sprintf(
                'banquet_%s_%s_%s_%02d_%s',
                date('Ymd_His'),
                substr((string) $left['safe_customer_name'], 0, 14),
                substr((string) $right['safe_customer_name'], 0, 14),
                $sheetNum,
                substr(bin2hex(random_bytes(2)), 0, 4)
            );
            $printFileName = $uniqueJobId . '.jpg';
            $destPrintPath = $printDir . DIRECTORY_SEPARATOR . $printFileName;

            $composeRes = $pairService->composeFilesForPrint(
                (string) $left['file_path'],
                (string) $right['file_path'],
                $destPrintPath,
                100
            );

            if (empty($composeRes['success'])) {
                return [
                    'success' => false,
                    'error' => $composeRes['error'] ?? 'Lỗi khi ghép 2 dải ảnh in tiệc.',
                    'printed_sheets' => $printedSheets,
                    'status' => $this->getBanquetStatus(),
                ];
            }

            // Create Windows Spooler / CUPS print job file
            $jobFile = $printDir . DIRECTORY_SEPARATOR . $uniqueJobId . '.job.json';
            $jobData = [
                'id' => $uniqueJobId,
                'type' => 'banquet_batch',
                'filename' => $printFileName,
                'printFile' => $destPrintPath,
                'copies' => 1,
                'left_customer' => $left['customer_name'],
                'right_customer' => $right['customer_name'],
                'left_file' => $left['banquet_file'],
                'right_file' => $right['banquet_file'],
                'paper_size' => (string) ($config['print']['paper_size'] ?? '4x6'),
                'orientation' => 'portrait',
                'margin' => (string) ($config['print']['margin'] ?? 'borderless'),
                'timestamp' => time(),
                'status' => 'pending',
            ];
            @file_put_contents($jobFile, json_encode($jobData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            // Execute system print command if configured
            if (!empty($config['commands']['print'])) {
                if (($config['print']['max_multi'] ?? 1) > 1) {
                    $cmd = sprintf($config['commands']['print'], 1, escapeshellarg($destPrintPath));
                } else {
                    $cmd = sprintf($config['commands']['print'], escapeshellarg($destPrintPath));
                }
                $cmd .= ' 2>&1';
                @exec($cmd, $cmdOut, $cmdRet);
                $logger->info('Banquet print command executed', ['job' => $uniqueJobId, 'return' => $cmdRet]);
            }

            // Record in PrintManagerService database
            $printManager->addToPrintDb($printFileName, $uniqueJobId);

            // Archive merged 4x6" sheet into 06_printed
            $archivedSheetPath = $printedDir . DIRECTORY_SEPARATOR . 'sheet_' . $printFileName;
            @copy($destPrintPath, $archivedSheetPath);

            // Count consumed copies per item
            $leftId = $left['item_id'];
            $consumedPerItem[$leftId] = ($consumedPerItem[$leftId] ?? 0) + 1;

            if (empty($right['is_bonus_duplicate'])) {
                $rightId = $right['item_id'];
                $consumedPerItem[$rightId] = ($consumedPerItem[$rightId] ?? 0) + 1;
            }

            $sheetSummary = [
                'job_id' => $uniqueJobId,
                'sheet_file' => 'sheet_' . $printFileName,
                'left_customer' => $left['customer_name'],
                'right_customer' => $right['customer_name'],
                'left_session' => $left['session_id'],
                'right_session' => $right['session_id'],
                'is_bonus_duplicate' => !empty($right['is_bonus_duplicate']),
                'printed_at' => time(),
                'printed_at_iso' => date('Y-m-d H:i:s'),
            ];
            $printedSheets[] = $sheetSummary;

            $this->appendPrintHistoryLog(array_merge(['type' => 'banquet_pair'], $sheetSummary));
        }

        // Now update or move consumed items from 05_print_banquet_pending -> 06_printed
        $banquetDir = FolderEnum::PIPELINE_PRINT_BANQUET->absolute();
        $movedToPrinted = [];

        foreach ($consumedPerItem as $itemId => $usedCount) {
            if (!isset($itemsById[$itemId])) {
                continue;
            }
            $item = $itemsById[$itemId];
            $oldRem = (int) $item['remaining_copies'];
            $newRem = max(0, $oldRem - $usedCount);
            $totalPrintedExtra = ((int) $item['printed_extra_copies']) + $usedCount;

            $oldJpgPath = (string) $item['file_path'];
            $oldJsonPath = (string) $item['json_path'];

            if ($newRem === 0) {
                // Move completed file to 06_printed
                $completedBase = sprintf(
                    '%s_%s_%dx_printed',
                    $item['session_id'],
                    $item['safe_customer_name'],
                    (int) $item['initial_extra_copies']
                );
                $destJpg = $printedDir . DIRECTORY_SEPARATOR . $completedBase . '.jpg';
                $destJson = $printedDir . DIRECTORY_SEPARATOR . $completedBase . '.json';

                if (is_file($oldJpgPath)) {
                    @copy($oldJpgPath, $destJpg);
                    @unlink($oldJpgPath);
                }

                $completedMeta = $item;
                unset($completedMeta['file_path'], $completedMeta['json_path'], $completedMeta['image_url']);
                $completedMeta['remaining_copies'] = 0;
                $completedMeta['printed_extra_copies'] = $totalPrintedExtra;
                $completedMeta['printed_file'] = $completedBase . '.jpg';
                $completedMeta['completed_at'] = time();
                $completedMeta['completed_at_iso'] = date('Y-m-d H:i:s');

                @file_put_contents(
                    $destJson,
                    json_encode($completedMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );

                if (is_file($oldJsonPath)) {
                    @unlink($oldJsonPath);
                }

                $movedToPrinted[] = $completedMeta;
            } else {
                // Still has remaining copies: rename in 05_print_banquet_pending to {session}_{Name}_{newRem}x.jpg
                $newBase = sprintf('%s_%s_%dx', $item['session_id'], $item['safe_customer_name'], $newRem);
                $newJpgPath = $banquetDir . DIRECTORY_SEPARATOR . $newBase . '.jpg';
                $newJsonPath = $banquetDir . DIRECTORY_SEPARATOR . $newBase . '.json';

                if (is_file($oldJpgPath) && $oldJpgPath !== $newJpgPath) {
                    @rename($oldJpgPath, $newJpgPath);
                }
                if (is_file($oldJsonPath)) {
                    @unlink($oldJsonPath);
                }

                $updatedMeta = $item;
                unset($updatedMeta['file_path'], $updatedMeta['json_path'], $updatedMeta['image_url']);
                $updatedMeta['remaining_copies'] = $newRem;
                $updatedMeta['printed_extra_copies'] = $totalPrintedExtra;
                $updatedMeta['banquet_file'] = $newBase . '.jpg';
                $updatedMeta['updated_at'] = time();

                @file_put_contents(
                    $newJsonPath,
                    json_encode($updatedMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }
        }

        return [
            'success' => true,
            'sheets_printed_count' => count($printedSheets),
            'printed_sheets' => $printedSheets,
            'moved_to_printed' => $movedToPrinted,
            'status' => $this->getBanquetStatus(),
        ];
    }

    /**
     * Append a record to 06_printed/banquet_print_log.json and 06_printed/print_history.csv.
     */
    private function appendPrintHistoryLog(array $entry): void
    {
        $printedDir = FolderEnum::PIPELINE_PRINTED->absolute();
        if (!is_dir($printedDir)) {
            FileUtility::createDirectory($printedDir);
        }

        $jsonLogFile = $printedDir . DIRECTORY_SEPARATOR . 'banquet_print_log.json';
        $history = [];
        if (is_file($jsonLogFile)) {
            $decoded = json_decode((string) @file_get_contents($jsonLogFile), true);
            if (is_array($decoded)) {
                $history = $decoded;
            }
        }
        array_unshift($history, $entry);
        if (count($history) > 200) {
            $history = array_slice($history, 0, 200);
        }
        @file_put_contents(
            $jsonLogFile,
            json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );

        $csvFile = $printedDir . DIRECTORY_SEPARATOR . 'print_history.csv';
        $isNewCsv = !is_file($csvFile);
        $fp = @fopen($csvFile, 'ab');
        if (is_resource($fp)) {
            if ($isNewCsv) {
                @fputcsv($fp, ['timestamp', 'type', 'job_id', 'left_info', 'right_info']);
            }
            @fputcsv($fp, [
                $entry['printed_at_iso'] ?? date('Y-m-d H:i:s'),
                $entry['type'] ?? 'print',
                $entry['job_id'] ?? '',
                $entry['left_customer'] ?? ($entry['left_file'] ?? ''),
                $entry['right_customer'] ?? ($entry['right_file'] ?? ''),
            ]);
            @fclose($fp);
        }
    }

    /**
     * Return recent completed items from 06_printed.
     */
    public function getRecentPrintedHistory(int $limit = 12): array
    {
        $printedDir = FolderEnum::PIPELINE_PRINTED->absolute();
        $jsonLogFile = $printedDir . DIRECTORY_SEPARATOR . 'banquet_print_log.json';
        if (!is_file($jsonLogFile)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($jsonLogFile), true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_slice($decoded, 0, $limit);
    }
}
