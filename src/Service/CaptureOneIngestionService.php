<?php

declare(strict_types=1);

namespace Photobooth\Service;

use Photobooth\Enum\FolderEnum;
use Photobooth\Utility\PathUtility;
use Psr\Log\LoggerInterface;

/**
 * CaptureOneIngestionService (Phase 4)
 *
 * Responsible for detecting, validating, stabilizing, deduplicating (idempotency),
 * reading EXIF metadata, and associating Capture One exported images with Photobooth
 * sessions and poses.
 */
class CaptureOneIngestionService
{
    private static ?self $instance = null;
    protected LoggerInterface $logger;

    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'tif', 'tiff'];
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/tiff'];

    public function __construct()
    {
        $this->logger = LoggerService::getInstance()->getLogger('c1-ingest');
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Validate that a file on disk is completely written, stable in size, readable,
     * and a valid JPEG/TIFF image.
     *
     * @return array{
     *   valid: bool,
     *   reason?: string,
     *   size?: int,
     *   width?: int,
     *   height?: int,
     *   mime?: string,
     *   sha1?: string,
     *   exif?: array<string, mixed>
     * }
     */
    public function verifyFileStabilityAndValidity(string $filePath, int $stabilityWaitMs = 350): array
    {
        clearstatcache(true, $filePath);
        if (!is_file($filePath) || !is_readable($filePath)) {
            return ['valid' => false, 'reason' => 'file_not_found_or_unreadable'];
        }

        $ext = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            return ['valid' => false, 'reason' => 'unsupported_extension:' . $ext];
        }

        // First size measurement
        $size1 = @filesize($filePath);
        if ($size1 === false || $size1 < 512) {
            return ['valid' => false, 'reason' => 'file_empty_or_too_small'];
        }

        // Wait for configured stability interval to ensure Capture One finished flushing buffers
        if ($stabilityWaitMs > 0) {
            usleep(max(50, min(3000, $stabilityWaitMs)) * 1000);
        }

        clearstatcache(true, $filePath);
        if (!is_file($filePath)) {
            return ['valid' => false, 'reason' => 'file_disappeared_during_stability_check'];
        }

        $size2 = @filesize($filePath);
        if ($size2 === false || $size1 !== $size2) {
            return ['valid' => false, 'reason' => 'file_size_still_changing'];
        }

        // Verify file is not locked for writing and has valid header/footer bytes
        $fp = @fopen($filePath, 'rb');
        if ($fp === false) {
            return ['valid' => false, 'reason' => 'file_locked_by_writer'];
        }
        $headerBytes = (string) @fread($fp, 4);
        $tailBytes = '';
        if ($size2 >= 2 && @fseek($fp, -2, SEEK_END) === 0) {
            $tailBytes = (string) @fread($fp, 2);
        }
        @fclose($fp);

        // For JPEG files, verify SOI (FF D8) and prevent truncated writes
        if ($ext === 'jpg' || $ext === 'jpeg') {
            if (strlen($headerBytes) < 2 || ord($headerBytes[0]) !== 0xFF || ord($headerBytes[1]) !== 0xD8) {
                return ['valid' => false, 'reason' => 'invalid_jpeg_soi_header'];
            }
            // Note: Some EXIF/XMP blocks pad trailing zero bytes after EOI, so we check getimagesize + image decode below
        }

        $imgInfo = @getimagesize($filePath);
        if (!is_array($imgInfo) || ($imgInfo[0] ?? 0) <= 0 || ($imgInfo[1] ?? 0) <= 0) {
            return ['valid' => false, 'reason' => 'corrupted_image_dimensions'];
        }

        $mime = strtolower((string) ($imgInfo['mime'] ?? ''));
        if (!in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            return ['valid' => false, 'reason' => 'unsupported_mime:' . $mime];
        }

        // Deep decode verification to confirm image is not half-written
        if (extension_loaded('imagick')) {
            try {
                $im = new \Imagick();
                if (!$im->pingImage($filePath)) {
                    $im->clear();
                    return ['valid' => false, 'reason' => 'imagick_ping_failed'];
                }
                $im->clear();
            } catch (\Throwable $e) {
                return ['valid' => false, 'reason' => 'imagick_exception:' . $e->getMessage()];
            }
        } elseif ($mime === 'image/jpeg') {
            $gd = @imagecreatefromjpeg($filePath);
            if (!$gd instanceof \GdImage) {
                return ['valid' => false, 'reason' => 'gd_jpeg_decode_failed'];
            }
            imagedestroy($gd);
        }

        $exifSummary = [];
        if (function_exists('exif_read_data') && $mime === 'image/jpeg') {
            $rawExif = @exif_read_data($filePath, 'IFD0,EXIF', false);
            if (is_array($rawExif)) {
                $exifSummary = array_filter([
                    'make' => $rawExif['Make'] ?? null,
                    'model' => $rawExif['Model'] ?? null,
                    'software' => $rawExif['Software'] ?? null,
                    'datetime' => $rawExif['DateTimeOriginal'] ?? ($rawExif['DateTime'] ?? null),
                    'iso' => $rawExif['ISOSpeedRatings'] ?? null,
                ], static fn($v) => $v !== null && $v !== '');
            }
        }

        $sha1 = (string) @sha1_file($filePath);

        return [
            'valid' => true,
            'size' => (int) $size2,
            'width' => (int) $imgInfo[0],
            'height' => (int) $imgInfo[1],
            'mime' => $mime,
            'sha1' => $sha1,
            'tail_eoi' => ($tailBytes === "\xFF\xD9"),
            'exif' => $exifSummary,
        ];
    }

    /**
     * Resolve session_id and pose_index from a filename or match against the oldest pending job in queue.
     *
     * Supported filename patterns:
     *  1. c1_<sessionId>_p<poseIdx>.jpg  (e.g. c1_20261114_103000_p01.jpg)
     *  2. <sessionId>-<poseIdx>.jpg      (e.g. 20261114_103000-0.jpg)
     *  3. <sessionId>.jpg                (single capture -> pose 0)
     *  4. Tethered sequential filename   (e.g. Capture_0001.jpg -> matched to active pending job in queue)
     *
     * @param array<string, array<string, mixed>> $jobs Active jobs from queue
     * @return array{job_id: string|null, session_id: string|null, pose_index: int}
     */
    public function resolveSessionAndPose(string $filename, array $jobs): array
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        // Strip Capture One duplicate suffixes like " (1)" or "_1" if exact match fails later
        $cleanBase = preg_replace('/\s+\(\d+\)$/', '', $base) ?? $base;

        // 1. Direct job_id match
        if (isset($jobs[$cleanBase])) {
            $j = $jobs[$cleanBase];
            return [
                'job_id' => (string) $cleanBase,
                'session_id' => (string) ($j['session_id'] ?? $cleanBase),
                'pose_index' => (int) ($j['pose_index'] ?? 0),
            ];
        }

        // 2. Pattern: c1_<sessionId>_p<poseIdx>
        if (preg_match('/^c1_([a-zA-Z0-9_]+)_p(\d+)/i', $cleanBase, $m)) {
            $sessionId = $m[1];
            $poseIndex = (int) $m[2];
            $jobId = sprintf('c1_%s_p%02d', $sessionId, $poseIndex);
            return [
                'job_id' => isset($jobs[$jobId]) ? $jobId : null,
                'session_id' => $sessionId,
                'pose_index' => $poseIndex,
            ];
        }

        // 3. Pattern: <sessionId>-<poseIdx>
        if (preg_match('/^([a-zA-Z0-9_]+)-(\d+)$/', $cleanBase, $m)) {
            $sessionId = $m[1];
            $poseIndex = (int) $m[2];
            $jobId = sprintf('c1_%s_p%02d', $sessionId, $poseIndex);
            return [
                'job_id' => isset($jobs[$jobId]) ? $jobId : null,
                'session_id' => $sessionId,
                'pose_index' => $poseIndex,
            ];
        }

        // 4. Match against pending jobs by expected_filename
        foreach ($jobs as $jobId => $job) {
            if (($job['status'] ?? '') !== 'pending') {
                continue;
            }
            $expectedBase = pathinfo((string) ($job['expected_filename'] ?? ''), PATHINFO_FILENAME);
            if ($expectedBase !== '' && strcasecmp($expectedBase, $cleanBase) === 0) {
                return [
                    'job_id' => (string) $jobId,
                    'session_id' => (string) ($job['session_id'] ?? ''),
                    'pose_index' => (int) ($job['pose_index'] ?? 0),
                ];
            }
        }

        // Do not match arbitrary un-associated files (e.g. Capture One recipe exports like "Photobooth 00043.jpg")
        return [
            'job_id' => null,
            'session_id' => null,
            'pose_index' => 0,
        ];
    }

    /**
     * Convert TIFF to high-quality sRGB JPEG if Capture One recipe was set to TIFF,
     * or copy JPEG directly to destination atomically.
     */
    public function deliverIngestedFile(string $sourcePath, string $destinationJpgPath, string $mime, int $jpegQuality = 94): bool
    {
        $destDir = dirname($destinationJpgPath);
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
        }

        $tmpDest = $destinationJpgPath . '.c1deliver.' . uniqid('', true) . '.tmp';

        if ($mime === 'image/tiff' && extension_loaded('imagick')) {
            try {
                $im = new \Imagick($sourcePath);
                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality(max(70, min(100, $jpegQuality)));
                $ok = $im->writeImage($tmpDest);
                $im->clear();
                if ($ok && is_file($tmpDest) && filesize($tmpDest) > 0) {
                    return @rename($tmpDest, $destinationJpgPath);
                }
            } catch (\Throwable $e) {
                $this->logger->error('TIFF to JPEG conversion failed: ' . $e->getMessage());
                @unlink($tmpDest);
                return false;
            }
        }

        if (@copy($sourcePath, $tmpDest) && is_file($tmpDest) && filesize($tmpDest) > 0) {
            return @rename($tmpDest, $destinationJpgPath);
        }

        @unlink($tmpDest);
        return false;
    }
}

