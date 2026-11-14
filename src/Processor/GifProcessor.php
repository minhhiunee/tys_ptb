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
     * Create a Korean-style Framed Time-Lapse GIF where each slot inside the Frame plays its own
     * sped-up 10s countdown video simultaneously, overlaid with the pristine Frame PNG.
     *
     * @param array<int, string[]> $poseFrameSequences Per-pose array of time-lapse frame file paths
     * @param array $layoutData Parsed layout JSON (width, height, frame, background_color, layout)
     * @param string $targetGifPath Output path for the animated .gif
     * @param int $delayMs Frame duration in milliseconds (default: 210ms ≈ 2x speedup from 420ms capture)
     * @param int $maxDimension Maximum output height/width in pixels (default: 720px)
     * @param string|null $presetId Optional color preset ID to apply to slot photos
     * @param int $presetAmount Optional preset intensity (0-100)
     */
    public function createFramedTimelapseGif(
        array $poseFrameSequences,
        array $layoutData,
        string $targetGifPath,
        int $delayMs = 210,
        int $maxDimension = 720,
        ?string $presetId = null,
        int $presetAmount = 100,
        ?string $baseCollageImagePath = null
    ): bool {
        $slots = $layoutData['layout'] ?? [];
        $fullW = (int) ($layoutData['width'] ?? 0);
        $fullH = (int) ($layoutData['height'] ?? 0);
        if (empty($slots) || $fullW <= 0 || $fullH <= 0 || empty($poseFrameSequences)) {
            return false;
        }

        $scale = min(1.0, $maxDimension / max($fullW, $fullH));
        $outW = max(1, (int) round($fullW * $scale));
        $outH = max(1, (int) round($fullH * $scale));

        $presetService = (!empty($presetId) && $presetId !== 'original' && $presetId !== 'none' && $presetAmount > 0)
            ? PresetService::getInstance()
            : null;

        $tempSynthFiles = [];
        $tempPresetFiles = [];
        $tempTickFiles = [];
        $scaledFrameImg = null;
        $baseCanvas = null;

        try {
            // Normalize each pose's sequence and synthesize motion if a pose only has 1 static frame
            $normalizedPoses = [];
            $maxSeqCount = 0;
            foreach (array_values($poseFrameSequences) as $seq) {
                $validSeq = [];
                if (is_array($seq)) {
                    foreach ($seq as $fPath) {
                        if (is_string($fPath) && is_file($fPath) && filesize($fPath) > 0) {
                            $validSeq[] = $fPath;
                        }
                    }
                }
                if (count($validSeq) === 1) {
                    $syn = $this->synthesizeTimelapseFromSingleFrame($validSeq[0], $maxDimension);
                    if (!empty($syn)) {
                        foreach ($syn as $sf) {
                            $tempSynthFiles[] = $sf;
                        }
                        $validSeq = $syn;
                    }
                }
                if (!empty($validSeq)) {
                    $normalizedPoses[] = $validSeq;
                    $maxSeqCount = max($maxSeqCount, count($validSeq));
                }
            }

            if (empty($normalizedPoses)) {
                return false;
            }

            $totalTicks = max(8, min(14, $maxSeqCount));
            // Preserve ~2x playback speed when source sequence exceeds the 14-tick cap
            $effectiveDelayMs = ($maxSeqCount > $totalTicks && $totalTicks > 0)
                ? min(500, (int) round($delayMs * ($maxSeqCount / $totalTicks)))
                : $delayMs;

            // Only resize + color-grade the unique pose frames actually sampled across $totalTicks
            if ($presetService !== null && $presetId !== null) {
                $usedFramePaths = [];
                $poseCount = count($normalizedPoses);
                for ($t = 0; $t < $totalTicks; $t++) {
                    foreach ($slots as $slotIdx => $slotDef) {
                        if (!is_array($slotDef) || count($slotDef) < 4) {
                            continue;
                        }
                        $poseSeq = $normalizedPoses[$slotIdx % $poseCount];
                        $seqLen = count($poseSeq);
                        $fIdx = ($totalTicks > 1) ? (int) round(($t / ($totalTicks - 1)) * ($seqLen - 1)) : 0;
                        $fIdx = max(0, min($seqLen - 1, $fIdx));
                        $usedFramePaths[$poseSeq[$fIdx]] = true;
                    }
                }

                $presetMap = [];
                $slotMaxDim = max(320, $maxDimension);
                foreach (array_keys($usedFramePaths) as $idx => $origFramePath) {
                    $tmpResized = sys_get_temp_dir() . DIRECTORY_SEPARATOR . sprintf('ptb_fsmall_%d_%s.jpg', $idx, uniqid());
                    $tmpPresetFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . sprintf('ptb_fpreset_%d_%s.jpg', $idx, uniqid());
                    $dummyW = null;
                    $dummyH = null;
                    $lutSource = $this->resizeFrameForGif($origFramePath, $tmpResized, $slotMaxDim, $dummyW, $dummyH)
                        ? $tmpResized
                        : $origFramePath;
                    if ($lutSource === $tmpResized) {
                        $tempPresetFiles[] = $tmpResized;
                    }
                    if ($presetService->applyPreset($lutSource, $tmpPresetFile, $presetId, $presetAmount, null, 88) && is_file($tmpPresetFile)) {
                        $tempPresetFiles[] = $tmpPresetFile;
                        $presetMap[$origFramePath] = $tmpPresetFile;
                    }
                }

                if (!empty($presetMap)) {
                    foreach ($normalizedPoses as $pIdx => $poseSeq) {
                        foreach ($poseSeq as $fIdx => $origPath) {
                            if (isset($presetMap[$origPath])) {
                                $normalizedPoses[$pIdx][$fIdx] = $presetMap[$origPath];
                            }
                        }
                    }
                }
            }

            // Pre-scale Frame PNG overlay once to ($outW, $outH)
            $frameRelPath = $layoutData['frame'] ?? '';
            if ($frameRelPath !== '') {
                $frameAbsPath = \Photobooth\Utility\PathUtility::getAbsolutePath((string) $frameRelPath);
                if (is_file($frameAbsPath)) {
                    $rawFrame = @imagecreatefrompng($frameAbsPath);
                    if ($rawFrame instanceof \GdImage) {
                        $scaledFrameImg = imagecreatetruecolor($outW, $outH);
                        if ($scaledFrameImg instanceof \GdImage) {
                            imagealphablending($scaledFrameImg, false);
                            imagesavealpha($scaledFrameImg, true);
                            $transparent = imagecolorallocatealpha($scaledFrameImg, 0, 0, 0, 127);
                            imagefilledrectangle($scaledFrameImg, 0, 0, $outW, $outH, $transparent);
                            imagecopyresampled(
                                $scaledFrameImg,
                                $rawFrame,
                                0,
                                0,
                                0,
                                0,
                                $outW,
                                $outH,
                                imagesx($rawFrame),
                                imagesy($rawFrame)
                            );
                        }
                        imagedestroy($rawFrame);
                    }
                }
            }

            // Prepare base canvas: prefer pristine final collage image to perfectly match colors, background, and borders
            if (!empty($baseCollageImagePath) && file_exists($baseCollageImagePath)) {
                $rawBase = @imagecreatefromjpeg($baseCollageImagePath);
                if ($rawBase instanceof \GdImage) {
                    $baseCanvas = imagecreatetruecolor($outW, $outH);
                    if ($baseCanvas instanceof \GdImage) {
                        imagecopyresampled($baseCanvas, $rawBase, 0, 0, 0, 0, $outW, $outH, imagesx($rawBase), imagesy($rawBase));
                    }
                    imagedestroy($rawBase);
                }
            }

            // Cache GdImage resources per pose frame path to avoid re-decoding the same frame
            $gdCache = [];

            for ($t = 0; $t < $totalTicks; $t++) {
                $canvas = imagecreatetruecolor($outW, $outH);
                if (!$canvas instanceof \GdImage) {
                    continue;
                }
                if ($baseCanvas instanceof \GdImage) {
                    imagecopy($canvas, $baseCanvas, 0, 0, 0, 0, $outW, $outH);
                } else {
                    $white = imagecolorallocate($canvas, 255, 255, 255);
                    imagefilledrectangle($canvas, 0, 0, $outW, $outH, $white);
                }

                foreach ($slots as $slotIdx => $slotDef) {
                    if (!is_array($slotDef) || count($slotDef) < 4) {
                        continue;
                    }
                    $dstX = (int) round(((float) $slotDef[0]) * $scale);
                    $dstY = (int) round(((float) $slotDef[1]) * $scale);
                    $dstW = max(1, (int) round(((float) $slotDef[2]) * $scale));
                    $dstH = max(1, (int) round(((float) $slotDef[3]) * $scale));

                    $poseSeq = $normalizedPoses[$slotIdx % count($normalizedPoses)];
                    $seqLen = count($poseSeq);
                    $fIdx = ($totalTicks > 1) ? (int) round(($t / ($totalTicks - 1)) * ($seqLen - 1)) : 0;
                    $fIdx = max(0, min($seqLen - 1, $fIdx));
                    $framePath = $poseSeq[$fIdx];

                    if (!isset($gdCache[$framePath])) {
                        $loaded = @imagecreatefromjpeg($framePath);
                        if (!$loaded) {
                            $loaded = @imagecreatefrompng($framePath);
                        }
                        $gdCache[$framePath] = $loaded ?: null;
                    }

                    $srcIm = $gdCache[$framePath];
                    if ($srcIm instanceof \GdImage) {
                        $srcW = imagesx($srcIm);
                        $srcH = imagesy($srcIm);
                        // Center-crop (aspect-fill) to match slot aspect ratio
                        $slotAspect = $dstW / $dstH;
                        $srcAspect = $srcW / $srcH;
                        if ($srcAspect > $slotAspect) {
                            $cropH = $srcH;
                            $cropW = max(1, (int) round($srcH * $slotAspect));
                            $cropX = (int) round(($srcW - $cropW) / 2);
                            $cropY = 0;
                        } else {
                            $cropW = $srcW;
                            $cropH = max(1, (int) round($srcW / $slotAspect));
                            $cropX = 0;
                            $cropY = (int) round(($srcH - $cropH) / 2);
                        }
                        imagecopyresampled($canvas, $srcIm, $dstX, $dstY, $cropX, $cropY, $dstW, $dstH, $cropW, $cropH);
                    }
                }

                // Overlay transparent Frame PNG on top of the slots
                if ($scaledFrameImg instanceof \GdImage) {
                    imagealphablending($canvas, true);
                    imagecopy($canvas, $scaledFrameImg, 0, 0, 0, 0, $outW, $outH);
                }

                $tickPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . sprintf('ptb_framed_tick_%02d_%s.jpg', $t, uniqid());
                if (imagejpeg($canvas, $tickPath, 88)) {
                    $tempTickFiles[] = $tickPath;
                    // Hold the final snapped pose for 1 extra tick at the end of the speedup loop
                    if ($t === $totalTicks - 1) {
                        $tempTickFiles[] = $tickPath;
                    }
                }
                imagedestroy($canvas);
            }

            foreach ($gdCache as $cachedIm) {
                if ($cachedIm instanceof \GdImage) {
                    imagedestroy($cachedIm);
                }
            }

            if (empty($tempTickFiles)) {
                return false;
            }

            $targetDir = dirname($targetGifPath);
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0755, true);
            }

            if (extension_loaded('imagick')) {
                if ($this->encodeWithImagick($tempTickFiles, $targetGifPath, $effectiveDelayMs)) {
                    return true;
                }
            }

            return $this->encodeWithGdGif89a($tempTickFiles, $targetGifPath, $effectiveDelayMs);
        } catch (\Throwable $e) {
            $this->logger->error('GifProcessor createFramedTimelapseGif error: ' . $e->getMessage());
            return false;
        } finally {
            if ($scaledFrameImg instanceof \GdImage) {
                imagedestroy($scaledFrameImg);
            }
            if ($baseCanvas instanceof \GdImage) {
                imagedestroy($baseCanvas);
            }
            foreach (array_unique(array_merge($tempSynthFiles, $tempPresetFiles, $tempTickFiles)) as $f) {
                if (is_string($f) && file_exists($f)) {
                    @unlink($f);
                }
            }
        }
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
        int $delayMs = 210,
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

        $targetDir = dirname($targetGifPath);
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        // Prepare resized (and optionally preset-colored) temp frames
        $tempFrames = [];
        $synthesizedFrames = [];
        $presetService = (!empty($presetId) && $presetId !== 'original' && $presetId !== 'none' && $presetAmount > 0)
            ? PresetService::getInstance()
            : null;

        try {
            // If only 1 frame exists (e.g. single photo without live video stream), synthesize an 8-frame time-lapse zoom + flash sequence
            if (count($validFrames) === 1) {
                $synthesizedFrames = $this->synthesizeTimelapseFromSingleFrame($validFrames[0], $maxDimension);
                if (!empty($synthesizedFrames)) {
                    $validFrames = $synthesizedFrames;
                } else {
                    $validFrames[] = $validFrames[0];
                }
            }

            $targetW = null;
            $targetH = null;

            foreach ($validFrames as $idx => $framePath) {
                $tempResized = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ptb_gif_frame_' . uniqid($idx . '_', true) . '.jpg';
                if ($this->resizeFrameForGif($framePath, $tempResized, $maxDimension, $targetW, $targetH)) {
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
            foreach ($synthesizedFrames as $syn) {
                if (file_exists($syn)) {
                    @unlink($syn);
                }
            }
        }
    }

    /**
     * Synthesize an 8-frame fast-forward motion + shutter-flash sequence when only 1 static frame is available
     *
     * @return string[]
     */
    protected function synthesizeTimelapseFromSingleFrame(string $sourcePath, int $maxDimension): array
    {
        $info = @getimagesize($sourcePath);
        if (!$info) {
            return [];
        }
        [$origW, $origH, $type] = $info;
        $srcImg = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => @imagecreatefrompng($sourcePath),
            IMAGETYPE_GIF => @imagecreatefromgif($sourcePath),
            default => false,
        };
        if (!$srcImg) {
            return [];
        }

        $scale = min(1.0, $maxDimension / max($origW, $origH));
        $outW = max(1, (int) round($origW * $scale));
        $outH = max(1, (int) round($origH * $scale));

        $frames = [];
        // 6 progressive zoom-out steps (simulating camera framing/posing lead-up) + 1 shutter flash + 2 final hold frames
        $zoomSteps = [1.22, 1.18, 1.14, 1.10, 1.06, 1.02, 1.00, 1.00, 1.00];
        foreach ($zoomSteps as $stepIdx => $zoom) {
            $canvas = imagecreatetruecolor($outW, $outH);
            if (!$canvas) {
                continue;
            }
            $cropW = max(1, (int) round($origW / $zoom));
            $cropH = max(1, (int) round($origH / $zoom));
            $srcX = (int) round(($origW - $cropW) / 2);
            $srcY = (int) round(($origH - $cropH) / 2);

            imagecopyresampled($canvas, $srcImg, 0, 0, $srcX, $srcY, $outW, $outH, $cropW, $cropH);

            // Step 6 is the shutter flash moment
            if ($stepIdx === 6) {
                imagefilter($canvas, IMG_FILTER_BRIGHTNESS, 55);
            }

            $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ptb_syn_tl_' . uniqid($stepIdx . '_', true) . '.jpg';
            if (imagejpeg($canvas, $tmpFile, 90)) {
                $frames[] = $tmpFile;
            }
            imagedestroy($canvas);
        }

        imagedestroy($srcImg);
        return $frames;
    }

    /**
     * Resize a single image frame down to $maxDimension for fast GIF encoding
     */
    protected function resizeFrameForGif(string $sourcePath, string $destPath, int $maxDimension, ?int &$lockedW = null, ?int &$lockedH = null): bool
    {
        $info = @getimagesize($sourcePath);
        if (!$info) {
            return false;
        }

        [$origW, $origH, $type] = $info;
        if ($origW <= 0 || $origH <= 0) {
            return false;
        }

        if ($lockedW !== null && $lockedH !== null) {
            $newW = $lockedW;
            $newH = $lockedH;
        } else {
            $scale = min(1.0, $maxDimension / max($origW, $origH));
            $newW = max(1, (int) round($origW * $scale));
            $newH = max(1, (int) round($origH * $scale));
            $lockedW = $newW;
            $lockedH = $newH;
        }

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
            // Shared 128-color palette + delta layer optimization cuts GIF size 3x-6x and speeds Cloud upload
            $animation->quantizeImages(128, \Imagick::COLORSPACE_RGB, 0, false, false);
            $optimized = $animation->optimizeImageLayers();
            $result = $optimized->writeImages($targetGifPath, true);

            $optimized->clear();
            $optimized->destroy();
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
            // Convert truecolor to palette for clean GIF frame (dither=false for 4x faster CPU encoding)
            imagetruecolortopalette($im, false, 256);
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

