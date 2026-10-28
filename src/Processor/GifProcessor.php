<?php

declare(strict_types=1);

namespace Photobooth\Processor;

use Photobooth\Service\LoggerService;
use Photobooth\Service\PresetService;
use Psr\Log\LoggerInterface;

class GifProcessor
{
    protected LoggerInterface $logger;

    public function __construct()
    {
        $this->logger = LoggerService::getInstance()->getLogger('main');
    }

    /**
     * Create an animated GIF from multiple image files.
     *
     * @param string[] $sourceFrames Ordered list of image file paths
     * @param string $targetGifPath Output path for the animated .gif
     * @param int $delayMs Frame duration in milliseconds (default: 450ms)
     * @param int $maxDimension Maximum width/height in pixels (default: 720px)
     * @param string|null $presetId Optional color preset ID to apply to each frame
     * @param int $presetAmount Optional preset intensity (0-100)
     * @return bool True on success
     */
    public function createAnimatedGif(
        array $sourceFrames,
        string $targetGifPath,
        int $delayMs = 450,
        int $maxDimension = 720,
        ?string $presetId = null,
        int $presetAmount = 100
    ): bool {
        $validFrames = [];
        foreach ($sourceFrames as $framePath) {
            if (is_string($framePath) && file_exists($framePath) && filesize($framePath) > 0) {
                $validFrames[] = $framePath;
            }
        }

        if (count($validFrames) === 0) {
            $this->logger->warning('GifProcessor: No valid source frames provided.');
            return false;
        }

        // If only 1 frame exists, duplicate it or create a subtle 2-frame pulse so GIF is still valid
        if (count($validFrames) === 1) {
            $validFrames[] = $validFrames[0];
        }

        $targetDir = dirname($targetGifPath);
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        // Prepare resized (and optionally preset-colored) temp frames
        $tempFrames = [];
        $presetService = (!empty($presetId) && $presetId !== 'original' && $presetId !== 'none' && $presetAmount > 0)
            ? PresetService::getInstance()
            : null;

        try {
            foreach ($validFrames as $idx => $framePath) {
                $tempResized = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ptb_gif_frame_' . uniqid($idx . '_', true) . '.jpg';
                if ($this->resizeFrameForGif($framePath, $tempResized, $maxDimension)) {
                    if ($presetService !== null && $presetId !== null) {
                        $presetService->applyPreset($tempResized, $tempResized, $presetId, $presetAmount);
                    }
                    $tempFrames[] = $tempResized;
                }
            }

            if (empty($tempFrames)) {
                return false;
            }

            // 1. Try Imagick first
            if (extension_loaded('imagick')) {
                $ok = $this->encodeWithImagick($tempFrames, $targetGifPath, $delayMs);
                if ($ok) {
                    return true;
                }
            }

            // 2. Fallback to Pure PHP GD GIF89a multi-frame encoder
            return $this->encodeWithGdGif89a($tempFrames, $targetGifPath, $delayMs);
        } catch (\Throwable $e) {
            $this->logger->error('GifProcessor error: ' . $e->getMessage());
            return false;
        } finally {
            foreach ($tempFrames as $tmp) {
                if (file_exists($tmp)) {
                    @unlink($tmp);
                }
            }
        }
    }

    /**
     * Resize a single image frame down to $maxDimension for fast GIF encoding
     */
    protected function resizeFrameForGif(string $sourcePath, string $destPath, int $maxDimension): bool
    {
        $info = @getimagesize($sourcePath);
        if (!$info) {
            return false;
        }

        [$origW, $origH, $type] = $info;
        if ($origW <= 0 || $origH <= 0) {
            return false;
        }

        $scale = min(1.0, $maxDimension / max($origW, $origH));
        $newW = max(1, (int) round($origW * $scale));
        $newH = max(1, (int) round($origH * $scale));

        $srcImg = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => @imagecreatefrompng($sourcePath),
            IMAGETYPE_GIF => @imagecreatefromgif($sourcePath),
            default => false,
        };

        if (!$srcImg) {
            return false;
        }

        $dstImg = imagecreatetruecolor($newW, $newH);
        if (!$dstImg) {
            imagedestroy($srcImg);
            return false;
        }

        imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        $saved = imagejpeg($dstImg, $destPath, 92);

        imagedestroy($srcImg);
        imagedestroy($dstImg);

        return $saved;
    }

    /**
     * Encode animated GIF using PHP Imagick extension
     *
     * @param string[] $framePaths
     */
    protected function encodeWithImagick(array $framePaths, string $targetGifPath, int $delayMs): bool
    {
        try {
            $animation = new \Imagick();
            $animation->setFormat('gif');

            // GIF delay is in ticks (1/100th of a second)
            $ticks = max(5, (int) round($delayMs / 10));

            foreach ($framePaths as $path) {
                $frame = new \Imagick($path);
                $frame->setImageFormat('gif');
                $frame->setImageDelay($ticks);
                $frame->setImageDispose(\Imagick::DISPOSE_NONE);
                $animation->addImage($frame);
                $frame->clear();
                $frame->destroy();
            }

            $animation->setImageIterations(0); // Infinite loop
            $result = $animation->writeImages($targetGifPath, true);

            $animation->clear();
            $animation->destroy();

            return $result && file_exists($targetGifPath);
        } catch (\Throwable $e) {
            $this->logger->warning('Imagick GIF encoding failed, falling back to GD: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Encode animated GIF89a using pure GD single-frame GIFs stitched into an animated stream
     *
     * @param string[] $framePaths
     */
    protected function encodeWithGdGif89a(array $framePaths, string $targetGifPath, int $delayMs): bool
    {
        $ticks = max(5, (int) round($delayMs / 10));
        $gifFrames = [];

        foreach ($framePaths as $path) {
            $im = @imagecreatefromjpeg($path);
            if (!$im) {
                continue;
            }
            // Convert truecolor to palette for clean GIF frame
            imagetruecolortopalette($im, true, 256);
            ob_start();
            imagegif($im);
            $rawGif = ob_get_clean();
            imagedestroy($im);

            if (is_string($rawGif) && strlen($rawGif) > 20) {
                $gifFrames[] = $rawGif;
            }
        }

        if (empty($gifFrames)) {
            return false;
        }

        $out = $this->muxAnimatedGifFrames($gifFrames, $ticks);
        if ($out === '') {
            return false;
        }

        return file_put_contents($targetGifPath, $out) !== false;
    }

    /**
     * Binary GIF89a Multiplexer: combines multiple single-frame GIF binary strings into an animated looping GIF
     *
     * @param string[] $rawGifFrames
     * @param int $delayTicks Delay in 1/100s
     */
    protected function muxAnimatedGifFrames(array $rawGifFrames, int $delayTicks): string
    {
        $first = $rawGifFrames[0];
        if (!str_starts_with($first, 'GIF87a') && !str_starts_with($first, 'GIF89a')) {
            return '';
        }

        // Logical Screen Descriptor is bytes 6..12 (7 bytes)
        $lsd = substr($first, 6, 7);
        $packed = ord($lsd[4]);
        $hasGct = ($packed & 0x80) !== 0;
        $gctSize = $hasGct ? (3 * (1 << (($packed & 0x07) + 1))) : 0;
        $globalColorTable = $hasGct ? substr($first, 13, $gctSize) : '';

        // Header + LSD + GCT + Netscape 2.0 Infinite Loop Application Extension
        $stream = 'GIF89a' . $lsd . $globalColorTable;
        $stream .= "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";

        foreach ($rawGifFrames as $idx => $gifData) {
            $fPacked = ord($gifData[10]);
            $fHasGct = ($fPacked & 0x80) !== 0;
            $fGctSize = $fHasGct ? (3 * (1 << (($fPacked & 0x07) + 1))) : 0;
            $fGct = $fHasGct ? substr($gifData, 13, $fGctSize) : '';

            $pos = 13 + $fGctSize;
            $len = strlen($gifData);

            // Advance to Image Descriptor (0x2C), skipping any existing extensions
            while ($pos < $len) {
                $blockId = ord($gifData[$pos]);
                if ($blockId === 0x2C) {
                    break;
                }
                if ($blockId === 0x21) {
                    // Extension block
                    $pos += 2;
                    while ($pos < $len) {
                        $subSize = ord($gifData[$pos]);
                        $pos++;
                        if ($subSize === 0) {
                            break;
                        }
                        $pos += $subSize;
                    }
                } else {
                    break;
                }
            }

            if ($pos >= $len || ord($gifData[$pos]) !== 0x2C) {
                continue;
            }

            // Graphic Control Extension (0x21 0xF9) with delay
            $delayLow = chr($delayTicks & 0xFF);
            $delayHigh = chr(($delayTicks >> 8) & 0xFF);
            $stream .= "\x21\xF9\x04\x04" . $delayLow . $delayHigh . "\x00\x00";

            // Image Descriptor is 10 bytes starting at $pos
            $imgDesc = substr($gifData, $pos, 10);
            // Strip trailing trailer byte (0x3B) from image data
            $imgBody = substr($gifData, $pos + 10, $len - ($pos + 10) - 1);

            if ($idx > 0 && $fHasGct) {
                // Attach Local Color Table for subsequent frames so each frame keeps its accurate palette
                $lctBits = $fPacked & 0x07;
                $descPacked = ord($imgDesc[9]);
                $descPacked = ($descPacked & 0x78) | 0x80 | $lctBits;
                $imgDesc[9] = chr($descPacked);
                $stream .= $imgDesc . $fGct . $imgBody;
            } else {
                $stream .= $imgDesc . $imgBody;
            }
        }

        // GIF Trailer
        $stream .= "\x3B";
        return $stream;
    }
}

