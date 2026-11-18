<?php

declare(strict_types=1);

namespace Photobooth\Service;

use Photobooth\Enum\FolderEnum;
use Photobooth\Utility\PathUtility;

/**
 * Manages the 2-Strip Pair-to-Print Queue for Canon SELPHY CP1500.
 *
 * Each photobooth capture produces one 4-pose vertical strip (984x2913, FramePTB4_1 or FramePTB4_2).
 * Guests can view photos, view high-res GIFs, and scan QR codes independently after every capture.
 * Printing is enabled only when 2 strips have been captured:
 *   - 1st strip -> placed on the LEFT half (x = 0..983) of FramePTB (1969x2913)
 *   - 2nd strip -> placed on the RIGHT half (x = 985..1968) of FramePTB (1969x2913)
 */
class PrintPairService
{
    private static ?self $instance = null;
    private string $queueFile;

    public const TARGET_WIDTH = 1969;
    public const TARGET_HEIGHT = 2913;
    public const STRIP_WIDTH = 984;
    public const STRIP_HEIGHT = 2913;
    public const RIGHT_STRIP_X = 985;

    private function __construct()
    {
        $this->queueFile = FolderEnum::DATA->absolute() . DIRECTORY_SEPARATOR . 'print_pair_queue.json';
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register or update a completed photo strip in the pair queue.
     */
    public function registerStrip(string $filename, string $layout = '', string $frame = ''): array
    {
        $cleanFile = basename(trim($filename));
        if ($cleanFile === '' || str_starts_with($cleanFile, 'preview_') || str_starts_with($cleanFile, 'orig_')) {
            return $this->getStatus();
        }

        $imagesDir = FolderEnum::IMAGES->absolute();
        $absPath = $imagesDir . DIRECTORY_SEPARATOR . $cleanFile;
        if (!is_file($absPath)) {
            // Give file system up to 300ms to flush file if called immediately after copy
            for ($attempt = 0; $attempt < 3; $attempt++) {
                usleep(100000);
                if (is_file($absPath)) {
                    break;
                }
            }
        }
        if (!is_file($absPath)) {
            $tempPath = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . $cleanFile;
            if (!is_file($tempPath)) {
                return $this->getStatus();
            }
        }

        $sessionId = pathinfo($cleanFile, PATHINFO_FILENAME);
        $metaPath = $imagesDir . DIRECTORY_SEPARATOR . 'meta_' . $cleanFile . '.json';
        if (($layout === '' || $frame === '') && is_file($metaPath)) {
            $meta = json_decode((string) @file_get_contents($metaPath), true);
            if (is_array($meta)) {
                $layout = $layout !== '' ? $layout : (string) ($meta['layout'] ?? '');
                $frame = $frame !== '' ? $frame : (string) ($meta['frame'] ?? '');
            }
        }

        $state = $this->loadState();
        $pending = $this->pruneStaleItems($state['pending'] ?? []);

        $found = false;
        foreach ($pending as &$item) {
            if (($item['filename'] ?? '') === $cleanFile || ($item['session_id'] ?? '') === $sessionId) {
                $item['filename'] = $cleanFile;
                $item['session_id'] = $sessionId;
                if ($layout !== '') {
                    $item['layout'] = $layout;
                }
                if ($frame !== '') {
                    $item['frame'] = $frame;
                }
                $item['updated_at'] = time();
                $found = true;
                break;
            }
        }
        unset($item);

        if (!$found) {
            $pending[] = [
                'session_id' => $sessionId,
                'filename' => $cleanFile,
                'layout' => $layout !== '' ? $layout : 'ptb4-1',
                'frame' => $frame !== '' ? $frame : 'private/frames/FramePTB4_1.png',
                'added_at' => time(),
                'updated_at' => time(),
            ];
        }

        // Keep at most 2 active strips (1st = Left, 2nd = Right)
        if (count($pending) > 2) {
            $pending = array_slice($pending, -2);
        }

        $state['pending'] = array_values($pending);
        $state['updated_at'] = time();
        $this->saveState($state);

        return $this->getStatus();
    }

    /**
     * Remove a strip from the pair queue (e.g. when user retakes or deletes the photo).
     */
    public function removeStrip(string $filenameOrSessionId): array
    {
        $clean = basename(trim($filenameOrSessionId));
        $sessionId = pathinfo($clean, PATHINFO_FILENAME);

        $state = $this->loadState();
        $pending = $this->pruneStaleItems($state['pending'] ?? []);

        $filtered = array_filter($pending, static function (array $item) use ($clean, $sessionId): bool {
            return ($item['filename'] ?? '') !== $clean && ($item['session_id'] ?? '') !== $sessionId;
        });

        $state['pending'] = array_values($filtered);
        $state['updated_at'] = time();
        $this->saveState($state);

        return $this->getStatus();
    }

    /**
     * Clear the pending pair queue (after successful print or manual admin reset).
     */
    public function clearQueue(?array $lastMergedInfo = null): array
    {
        $state = $this->loadState();
        $state['pending'] = [];
        if ($lastMergedInfo !== null) {
            $state['last_merged_print'] = $lastMergedInfo;
        }
        $state['updated_at'] = time();
        $this->saveState($state);

        return $this->getStatus();
    }

    /**
     * Return current pair queue status for UI & Print API.
     */
    public function getStatus(): array
    {
        $state = $this->loadState();
        $pending = $this->pruneStaleItems($state['pending'] ?? []);
        if (count($pending) !== count($state['pending'] ?? [])) {
            $state['pending'] = $pending;
            $this->saveState($state);
        }

        $count = count($pending);
        $canPrint = $count >= 2;

        $items = [];
        foreach ($pending as $idx => $item) {
            $fn = (string) ($item['filename'] ?? '');
            $items[] = [
                'position' => $idx === 0 ? 'left' : 'right',
                'position_label' => $idx === 0 ? 'Nửa Trái (Chụp trước)' : 'Nửa Phải (Chụp sau)',
                'session_id' => (string) ($item['session_id'] ?? ''),
                'filename' => $fn,
                'layout' => (string) ($item['layout'] ?? 'ptb4-1'),
                'frame' => (string) ($item['frame'] ?? ''),
                'image_url' => $fn !== '' ? PathUtility::getPublicPath('data/images/' . $fn) : '',
                'added_at' => (int) ($item['added_at'] ?? 0),
            ];
        }

        $message = match ($count) {
            0 => 'Chưa có ảnh chờ in (0/2) • Cần chụp 2 lượt khung PTB4 để ghép in',
            1 => 'Đã lưu 1/2 dải ảnh • Chụp thêm 1 lượt nữa để mở khóa In ảnh',
            default => 'Đã đủ 2/2 dải ảnh • Sẵn sàng ghép in Canon SELPHY CP1500',
        };

        return [
            'count' => $count,
            'required' => 2,
            'can_print' => $canPrint,
            'pending' => $items,
            'last_merged_print' => $state['last_merged_print'] ?? null,
            'message' => $message,
        ];
    }

    /**
     * Compose the 2 pending PTB4 strips side-by-side onto the FramePTB (1969x2913) canvas.
     *
     * - 1st strip ($pending[0]) -> Left half  (x = 0,   y = 0, w = 984, h = 2913)
     * - 2nd strip ($pending[1]) -> Right half (x = 985, y = 0, w = 984, h = 2913)
     *
     * Supports mixing any combination of FramePTB4_1 and FramePTB4_2 because each strip
     * already has its chosen frame baked onto it.
     *
     * @return array{success: bool, error?: string, left_file?: string, right_file?: string, width?: int, height?: int}
     */
    public function composePairForPrint(string $destPrintPath, int $jpegQuality = 100): array
    {
        $status = $this->getStatus();
        if (!$status['can_print'] || count($status['pending']) < 2) {
            return [
                'success' => false,
                'error' => 'Chưa đủ 2 dải ảnh PTB4 để ghép in (Hiện có ' . $status['count'] . '/2). Vui lòng chụp thêm lượt tiếp theo!',
            ];
        }

        $leftItem = $status['pending'][0];
        $rightItem = $status['pending'][1];

        $imagesDir = FolderEnum::IMAGES->absolute();
        $leftPath = $imagesDir . DIRECTORY_SEPARATOR . $leftItem['filename'];
        $rightPath = $imagesDir . DIRECTORY_SEPARATOR . $rightItem['filename'];

        if (!is_file($leftPath) || !is_file($rightPath)) {
            return [
                'success' => false,
                'error' => 'Không tìm thấy file ảnh gốc của 1 trong 2 lượt chụp để ghép in.',
            ];
        }

        $canvas = imagecreatetruecolor(self::TARGET_WIDTH, self::TARGET_HEIGHT);
        if (!$canvas instanceof \GdImage) {
            return [
                'success' => false,
                'error' => 'Không thể khởi tạo vùng nhớ ghép ảnh in (1969x2913).',
            ];
        }

        // Fill clean white background first
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, self::TARGET_WIDTH, self::TARGET_HEIGHT, $white);

        // Optional base FramePTB.png underlayer (fills the 1px center seam at x=984 naturally)
        $baseFramePath = PathUtility::getAbsolutePath('private/frames/FramePTB.png');
        if (is_file($baseFramePath)) {
            $baseFrameImg = @imagecreatefrompng($baseFramePath);
            if ($baseFrameImg instanceof \GdImage) {
                imagealphablending($canvas, true);
                imagecopyresampled(
                    $canvas,
                    $baseFrameImg,
                    0,
                    0,
                    0,
                    0,
                    self::TARGET_WIDTH,
                    self::TARGET_HEIGHT,
                    imagesx($baseFrameImg),
                    imagesy($baseFrameImg)
                );
                imagedestroy($baseFrameImg);
            }
        }

        $leftImg = $this->loadImageResource($leftPath);
        $rightImg = $this->loadImageResource($rightPath);

        if (!$leftImg instanceof \GdImage || !$rightImg instanceof \GdImage) {
            if ($leftImg instanceof \GdImage) {
                imagedestroy($leftImg);
            }
            if ($rightImg instanceof \GdImage) {
                imagedestroy($rightImg);
            }
            imagedestroy($canvas);
            return [
                'success' => false,
                'error' => 'Không thể đọc dữ liệu ảnh của 2 lượt chụp để ghép in.',
            ];
        }

        // Place 1st captured strip on the exact LEFT half (x = 0..983, w = 984, h = 2913)
        imagecopyresampled(
            $canvas,
            $leftImg,
            0,
            0,
            0,
            0,
            self::STRIP_WIDTH,
            self::STRIP_HEIGHT,
            imagesx($leftImg),
            imagesy($leftImg)
        );

        // Place 2nd captured strip on the exact RIGHT half (x = 985..1968, w = 984, h = 2913)
        imagecopyresampled(
            $canvas,
            $rightImg,
            self::RIGHT_STRIP_X,
            0,
            0,
            0,
            self::STRIP_WIDTH,
            self::STRIP_HEIGHT,
            imagesx($rightImg),
            imagesy($rightImg)
        );

        imagedestroy($leftImg);
        imagedestroy($rightImg);

        // Draw subtle hair-line cut guide marks at the top & bottom of the center seam (x = 984) for precision cutting
        $cutGuideColor = imagecolorallocate($canvas, 180, 188, 200);
        imageline($canvas, self::STRIP_WIDTH, 0, self::STRIP_WIDTH, 28, $cutGuideColor);
        imageline($canvas, self::STRIP_WIDTH, self::TARGET_HEIGHT - 29, self::STRIP_WIDTH, self::TARGET_HEIGHT - 1, $cutGuideColor);

        $destDir = dirname($destPrintPath);
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
        }

        // Set 300 DPI resolution header in JPEG via imagejpeg
        $saved = imagejpeg($canvas, $destPrintPath, max(85, min(100, $jpegQuality)));
        imagedestroy($canvas);
        if ($saved && is_file($destPrintPath)) {
            @chmod($destPrintPath, 0666);
        }

        if (!$saved || !is_file($destPrintPath)) {
            return [
                'success' => false,
                'error' => 'Lỗi khi xuất file ảnh ghép in ra ổ đĩa.',
            ];
        }

        return [
            'success' => true,
            'left_file' => $leftItem['filename'],
            'right_file' => $rightItem['filename'],
            'left_layout' => $leftItem['layout'],
            'right_layout' => $rightItem['layout'],
            'width' => self::TARGET_WIDTH,
            'height' => self::TARGET_HEIGHT,
        ];
    }

    private function loadImageResource(string $path): ?\GdImage
    {
        $im = @imagecreatefromjpeg($path);
        if ($im instanceof \GdImage) {
            return $im;
        }
        $im = @imagecreatefrompng($path);
        return ($im instanceof \GdImage) ? $im : null;
    }

    /**
     * Filter out missing files or items older than 6 hours.
     */
    private function pruneStaleItems(array $pending): array
    {
        $imagesDir = FolderEnum::IMAGES->absolute();
        $now = time();
        $maxAge = 6 * 3600;
        $valid = [];

        foreach ($pending as $item) {
            if (!is_array($item) || empty($item['filename'])) {
                continue;
            }
            $fn = basename((string) $item['filename']);
            $abs = $imagesDir . DIRECTORY_SEPARATOR . $fn;
            if (!is_file($abs)) {
                continue;
            }
            $addedAt = (int) ($item['added_at'] ?? $now);
            if (($now - $addedAt) > $maxAge) {
                continue;
            }
            $valid[] = $item;
        }

        return array_values($valid);
    }

    private function loadState(): array
    {
        if (!is_file($this->queueFile)) {
            return ['pending' => [], 'last_merged_print' => null, 'updated_at' => 0];
        }
        $raw = @file_get_contents($this->queueFile);
        if (!is_string($raw) || trim($raw) === '') {
            return ['pending' => [], 'last_merged_print' => null, 'updated_at' => 0];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : ['pending' => [], 'last_merged_print' => null, 'updated_at' => 0];
    }

    private function saveState(array $state): void
    {
        $dir = dirname($this->queueFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents(
            $this->queueFile,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
        @chmod($this->queueFile, 0666);
    }
}
