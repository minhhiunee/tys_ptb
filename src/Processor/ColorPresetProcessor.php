<?php

declare(strict_types=1);

namespace Photobooth\Processor;

use Photobooth\Service\LoggerService;
use Psr\Log\LoggerInterface;

class ColorPresetProcessor
{
    protected LoggerInterface $logger;

    public function __construct()
    {
        $this->logger = LoggerService::getInstance()->getLogger('preset');
    }

    /**
     * Apply a color preset to an image and save the result to $targetImagePath.
     * The original source image is NEVER modified.
     *
     * @param string $sourceImagePath Path to original input image
     * @param string $targetImagePath Path where final processed image will be saved
     * @param string|null $lutPath Optional path to a HaldCLUT image
     * @param array $xmpParams Parsed parameters from XMP file
     * @param int $amount Intensity percentage (0 to 100)
     * @return bool True on success, false on failure
     */
    public function applyPreset(
        string $sourceImagePath,
        string $targetImagePath,
        ?string $lutPath = null,
        array $xmpParams = [],
        int $amount = 100
    ): bool {
        $amount = max(0, min(100, $amount));

        if (!file_exists($sourceImagePath)) {
            $this->logger->error("Source image not found: {$sourceImagePath}");
            return false;
        }

        // If amount is 0, simply copy the original unmodified image
        if ($amount === 0) {
            $this->logger->debug("Amount is 0; copying original image unmodified to target.");
            return copy($sourceImagePath, $targetImagePath);
        }

        // If no LUT and no XMP params, copy original
        if (empty($lutPath) && empty($xmpParams)) {
            return copy($sourceImagePath, $targetImagePath);
        }

        if (extension_loaded('imagick')) {
            return $this->applyWithImagick($sourceImagePath, $targetImagePath, $lutPath, $xmpParams, $amount);
        }

        return $this->applyWithGd($sourceImagePath, $targetImagePath, $lutPath, $xmpParams, $amount);
    }

    /**
     * Apply preset using PHP Imagick with HaldCLUT and opacity blending
     */
    protected function applyWithImagick(
        string $sourceImagePath,
        string $targetImagePath,
        ?string $lutPath,
        array $xmpParams,
        int $amount
    ): bool {
        try {
            $original = new \Imagick($sourceImagePath);
            $original->transformImageColorspace(\Imagick::COLORSPACE_SRGB);

            // Clone image for applying adjustments
            $processed = clone $original;

            if (!empty($lutPath) && file_exists($lutPath)) {
                $lut = new \Imagick($lutPath);
                $processed->haldClutImage($lut);
                $lut->clear();
                $lut->destroy();
            } else {
                // Apply approximate XMP parameters if no LUT image exists
                $this->applyXmpParamsToImagick($processed, $xmpParams);
            }

            if ($amount >= 100) {
                $result = $processed->writeImage($targetImagePath);
                $processed->clear();
                $processed->destroy();
                $original->clear();
                $original->destroy();
                return $result;
            }

            // Blend processed image over original with linear opacity
            $opacity = $amount / 100.0;
            $processed->setImageAlphaChannel(\Imagick::ALPHACHANNEL_SET);
            $processed->evaluateImage(\Imagick::EVALUATE_MULTIPLY, $opacity, \Imagick::CHANNEL_ALPHA);

            $original->compositeImage($processed, \Imagick::COMPOSITE_OVER, 0, 0);
            $result = $original->writeImage($targetImagePath);

            $processed->clear();
            $processed->destroy();
            $original->clear();
            $original->destroy();

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error("Imagick preset processing failed: " . $e->getMessage());
            // Fallback to copy original if processing fails
            return copy($sourceImagePath, $targetImagePath);
        }
    }

    /**
     * Apply XMP tone adjustments directly on an Imagick image object
     */
    protected function applyXmpParamsToImagick(\Imagick $image, array $params): void
    {
        $exposure = (float) ($params['Exposure2012'] ?? 0);
        $contrast = (float) ($params['Contrast2012'] ?? 0);
        $saturation = (float) ($params['Saturation'] ?? 0);
        $vibrance = (float) ($params['Vibrance'] ?? 0);

        // Modulate: Brightness, Saturation, Hue (100 = 100% / unchanged)
        $modulateBrightness = 100.0;
        $modulateSaturation = 100.0;

        if ($exposure !== 0.0) {
            // Exposure roughly +1 EV = +20% brightness
            $modulateBrightness += ($exposure * 20.0);
        }

        $totalSat = $saturation + ($vibrance * 0.5);
        if ($totalSat !== 0.0) {
            $modulateSaturation += ($totalSat * 0.8);
        }

        $modulateBrightness = max(10.0, min(200.0, $modulateBrightness));
        $modulateSaturation = max(0.0, min(250.0, $modulateSaturation));

        if ($modulateBrightness !== 100.0 || $modulateSaturation !== 100.0) {
            $image->modulateImage($modulateBrightness, $modulateSaturation, 100);
        }

        // Contrast adjustment
        if ($contrast !== 0.0) {
            // Contrast2012 ranges from -100 to +100
            // In ImageMagick, sigmoidalContrastImage or brightnessContrastImage
            if (method_exists($image, 'brightnessContrastImage')) {
                $image->brightnessContrastImage(0, $contrast * 0.3);
            }
        }
    }

    /**
     * Fallback to GD if Imagick is not available
     */
    protected function applyWithGd(
        string $sourceImagePath,
        string $targetImagePath,
        ?string $lutPath,
        array $xmpParams,
        int $amount
    ): bool {
        try {
            $src = imagecreatefromjpeg($sourceImagePath);
            if (!$src) {
                return copy($sourceImagePath, $targetImagePath);
            }

            $w = imagesx($src);
            $h = imagesy($src);

            $processed = imagecreatetruecolor($w, $h);
            imagecopy($processed, $src, 0, 0, 0, 0, $w, $h);

            // Apply contrast and brightness adjustments using GD filters
            $contrast = (float) ($xmpParams['Contrast2012'] ?? 0);
            $exposure = (float) ($xmpParams['Exposure2012'] ?? 0);

            if ($contrast !== 0.0) {
                // GD: -100 = max contrast, +100 = min contrast (inverted in GD)
                $gdContrast = (int) round(-($contrast * 0.5));
                imagefilter($processed, IMG_FILTER_CONTRAST, $gdContrast);
            }

            if ($exposure !== 0.0) {
                $gdBrightness = (int) round($exposure * 40);
                imagefilter($processed, IMG_FILTER_BRIGHTNESS, $gdBrightness);
            }

            // Blend back according to amount
            if ($amount < 100) {
                imagecopymerge($src, $processed, 0, 0, 0, 0, $w, $h, $amount);
                $final = $src;
            } else {
                $final = $processed;
            }

            $result = imagejpeg($final, $targetImagePath, 95);
            imagedestroy($src);
            imagedestroy($processed);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error("GD preset processing failed: " . $e->getMessage());
            return copy($sourceImagePath, $targetImagePath);
        }
    }
}
