<?php

declare(strict_types=1);

namespace Photobooth\Service;

use Photobooth\Dto\PresetDto;
use Photobooth\Processor\ColorPresetProcessor;
use Photobooth\Utility\PathUtility;
use Psr\Log\LoggerInterface;

class PresetService
{
    private static ?self $instance = null;
    protected LoggerInterface $logger;
    protected string $configFile;
    protected array $presets = [];
    protected ColorPresetProcessor $processor;

    private function __construct()
    {
        $this->logger = LoggerService::getInstance()->getLogger('preset');
        $this->configFile = PathUtility::getAbsolutePath('config/presets.json');
        $this->processor = new ColorPresetProcessor();
        $this->initPresets();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Initialize preset list from config file or scan default PresetColor/ folder
     */
    protected function initPresets(): void
    {
        if (file_exists($this->configFile)) {
            $raw = file_get_contents($this->configFile);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $item) {
                        if (is_array($item)) {
                            $dto = PresetDto::fromArray($item);
                            $this->presets[$dto->id] = $dto;
                        }
                    }
                }
            }
        }

        // If no presets loaded, initialize standard presets from PresetColor/
        if (empty($this->presets)) {
            $this->initDefaultPresets();
            $this->savePresets();
        }

        // Ensure LUT files exist for the enabled presets
        $this->ensureLutsExist();
    }

    /**
     * Create default configuration for Clean Portrait and Sky 4k +
     */
    protected function initDefaultPresets(): void
    {
        $cleanPortraitPath = PathUtility::getAbsolutePath('PresetColor/Clean Portrait.xmp');
        $cleanMeta = file_exists($cleanPortraitPath) ? $this->parseXmpFile($cleanPortraitPath) : ['params' => [], 'exact' => [], 'approx' => [], 'unsupported' => []];

        $cleanDto = new PresetDto();
        $cleanDto->id = 'clean-portrait';
        $cleanDto->name = 'Clean Portrait';
        $cleanDto->group = 'TYS TEST';
        $cleanDto->version = '1.0';
        $cleanDto->format = 'xmp_lut';
        $cleanDto->sourceFile = 'PresetColor/Clean Portrait.xmp';
        $cleanDto->lutFile = 'private/presets/clean-portrait.png';
        $cleanDto->supportsAmount = true;
        $cleanDto->defaultAmount = 100;
        $cleanDto->enabled = true;
        $cleanDto->exactParams = $cleanMeta['exact'];
        $cleanDto->approximatedParams = $cleanMeta['approx'];
        $cleanDto->unsupportedParams = $cleanMeta['unsupported'];
        $this->presets[$cleanDto->id] = $cleanDto;

        $skyPath = PathUtility::getAbsolutePath('PresetColor/Sky4k.xmp');
        $skyMeta = file_exists($skyPath) ? $this->parseXmpFile($skyPath) : ['params' => [], 'exact' => [], 'approx' => [], 'unsupported' => []];

        $skyDto = new PresetDto();
        $skyDto->id = 'sky-4k';
        $skyDto->name = 'Sky 4k +';
        $skyDto->group = 'TYS TEST';
        $skyDto->version = '1.0';
        $skyDto->format = 'xmp_lut';
        $skyDto->sourceFile = 'PresetColor/Sky4k.xmp';
        $skyDto->lutFile = 'private/presets/sky-4k.png';
        $skyDto->supportsAmount = true;
        $skyDto->defaultAmount = 100;
        $skyDto->enabled = true;
        $skyDto->exactParams = $skyMeta['exact'];
        $skyDto->approximatedParams = $skyMeta['approx'];
        $skyDto->unsupportedParams = $skyMeta['unsupported'];
        $this->presets[$skyDto->id] = $skyDto;
    }

    /**
     * Ensure HaldCLUT PNG files exist for presets
     */
    public function ensureLutsExist(): void
    {
        $identityPath = PathUtility::getAbsolutePath('resources/img/identity_hald8.png');
        if (!file_exists($identityPath)) {
            return;
        }

        foreach ($this->presets as $preset) {
            if ($preset->lutFile) {
                $targetLut = PathUtility::getAbsolutePath($preset->lutFile);
                if (!file_exists($targetLut)) {
                    $sourceXmp = PathUtility::getAbsolutePath($preset->sourceFile);
                    $xmpMeta = file_exists($sourceXmp) ? $this->parseXmpFile($sourceXmp) : ['params' => []];
                    $this->bakeLutFromXmp($identityPath, $targetLut, $xmpMeta['params']);
                }
            }
        }
    }

    /**
     * Bake a HaldCLUT file from identity Hald and XMP parameters using Imagick
     */
    public function bakeLutFromXmp(string $identityHaldPath, string $outputLutPath, array $xmpParams): bool
    {
        if (!file_exists($identityHaldPath)) {
            return false;
        }

        $dir = dirname($outputLutPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (extension_loaded('imagick')) {
            try {
                $lut = new \Imagick($identityHaldPath);
                $lut->transformImageColorspace(\Imagick::COLORSPACE_SRGB);

                $exposure = (float) ($xmpParams['Exposure2012'] ?? 0);
                $contrast = (float) ($xmpParams['Contrast2012'] ?? 0);
                $saturation = (float) ($xmpParams['Saturation'] ?? 0);
                $vibrance = (float) ($xmpParams['Vibrance'] ?? 0);

                $brightness = 100.0 + ($exposure * 18.0);
                $sat = 100.0 + (($saturation + ($vibrance * 0.5)) * 0.7);

                $brightness = max(20.0, min(180.0, $brightness));
                $sat = max(10.0, min(220.0, $sat));

                if ($brightness !== 100.0 || $sat !== 100.0) {
                    $lut->modulateImage($brightness, $sat, 100);
                }

                if ($contrast !== 0.0 && method_exists($lut, 'brightnessContrastImage')) {
                    $lut->brightnessContrastImage(0, $contrast * 0.25);
                }

                $lut->setImageFormat('png');
                $lut->writeImage($outputLutPath);
                $lut->clear();
                $lut->destroy();

                $this->logger->info("Baked LUT to {$outputLutPath}");
                return true;
            } catch (\Throwable $e) {
                $this->logger->error("Failed baking LUT: " . $e->getMessage());
            }
        }

        return copy($identityHaldPath, $outputLutPath);
    }

    /**
     * Parse XMP file to extract Camera Raw settings and categorize parameters
     */
    public function parseXmpFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return ['params' => [], 'exact' => [], 'approx' => [], 'unsupported' => []];
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return ['params' => [], 'exact' => [], 'approx' => [], 'unsupported' => []];
        }

        $params = [];
        $exact = [];
        $approx = [];
        $unsupported = [];

        // Match all crs:Attribute="Value"
        preg_match_all('/crs:([A-Za-z0-9_]+)="([^"]*)"/', $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $key = $match[1];
            $value = $match[2];
            $params[$key] = $value;

            // Categorize parameters
            if (in_array($key, [
                'Exposure2012', 'Contrast2012', 'Saturation', 'Vibrance',
                'ColorGradeMidtoneHue', 'ColorGradeMidtoneSat', 'ToneCurveName2012',
                'HueAdjustmentRed', 'HueAdjustmentOrange', 'HueAdjustmentYellow',
                'SaturationAdjustmentRed', 'SaturationAdjustmentOrange', 'SaturationAdjustmentYellow',
            ], true)) {
                $exact[$key] = $value;
            } elseif (in_array($key, [
                'Highlights2012', 'Shadows2012', 'Whites2012', 'Blacks2012',
                'Clarity2012', 'ParametricShadows', 'ParametricDarks', 'ParametricLights', 'ParametricHighlights',
            ], true)) {
                $approx[$key] = $value;
            } else {
                $unsupported[$key] = $value;
            }
        }

        return [
            'params' => $params,
            'exact' => $exact,
            'approx' => $approx,
            'unsupported' => $unsupported,
        ];
    }

    /**
     * Get all registered presets
     * @return PresetDto[]
     */
    public function getAllPresets(): array
    {
        return array_values($this->presets);
    }

    /**
     * Get enabled presets
     * @return PresetDto[]
     */
    public function getEnabledPresets(): array
    {
        return array_values(array_filter($this->presets, fn(PresetDto $p) => $p->enabled));
    }

    /**
     * Get preset by ID
     */
    public function getPresetById(string $id): ?PresetDto
    {
        return $this->presets[$id] ?? null;
    }

    /**
     * Apply preset to image
     */
    public function applyPreset(
        string $sourceImagePath,
        string $targetImagePath,
        string $presetId,
        int $amount = 100
    ): bool {
        if ($presetId === 'original' || $presetId === 'none' || $presetId === '') {
            return copy($sourceImagePath, $targetImagePath);
        }

        $preset = $this->getPresetById($presetId);
        if (!$preset) {
            $this->logger->warning("Preset {$presetId} not found, copying original");
            return copy($sourceImagePath, $targetImagePath);
        }

        $lutAbsolutePath = $preset->lutFile ? PathUtility::getAbsolutePath($preset->lutFile) : null;
        $xmpAbsolutePath = PathUtility::getAbsolutePath($preset->sourceFile);
        $xmpMeta = file_exists($xmpAbsolutePath) ? $this->parseXmpFile($xmpAbsolutePath) : ['params' => []];

        return $this->processor->applyPreset(
            $sourceImagePath,
            $targetImagePath,
            $lutAbsolutePath,
            $xmpMeta['params'],
            $amount
        );
    }

    /**
     * Save presets back to config/presets.json
     */
    public function savePresets(): bool
    {
        $data = array_map(fn(PresetDto $p) => $p->toArray(), array_values($this->presets));
        return (bool) file_put_contents(
            $this->configFile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }
}
