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

        // Auto-discover any new presets placed in PresetColor/ folder
        $this->discoverPresetsFromDirectory();

        // Ensure LUT files exist for the enabled presets
        $this->ensureLutsExist();
    }

    /**
     * Auto-discover preset files (.xmp, .cube) in PresetColor/ directory
     */
    public function discoverPresetsFromDirectory(): void
    {
        $presetDir = PathUtility::getAbsolutePath('PresetColor');
        if (!is_dir($presetDir)) {
            return;
        }

        $files = scandir($presetDir);
        if ($files === false) {
            return;
        }

        $dirty = false;
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($ext !== 'xmp' && $ext !== 'cube') {
                continue;
            }

            $sourceRel = 'PresetColor/' . $file;
            $fullPath = $presetDir . DIRECTORY_SEPARATOR . $file;

            // Check if already registered
            $alreadyExists = false;
            foreach ($this->presets as $p) {
                if (strtolower($p->sourceFile) === strtolower($sourceRel) || basename($p->sourceFile) === $file) {
                    $alreadyExists = true;
                    break;
                }
            }

            if ($alreadyExists) {
                continue;
            }

            $baseNameNoExt = pathinfo($file, PATHINFO_FILENAME);
            $slug = self::slugify($baseNameNoExt);
            if (empty($slug)) {
                $slug = 'preset-' . substr(md5($file), 0, 8);
            }

            $xmpMeta = ($ext === 'xmp') ? $this->parseXmpFile($fullPath) : ['params' => [], 'exact' => [], 'approx' => [], 'unsupported' => []];
            $displayName = $xmpMeta['params']['_Name'] ?? self::formatTitle($baseNameNoExt);
            $lutRel = 'private/presets/' . $slug . '.png';

            $dto = new PresetDto();
            $dto->id = $slug;
            $dto->name = $displayName;
            $dto->group = 'TYS TEST';
            $dto->version = '1.0';
            $dto->format = 'xmp_lut';
            $dto->sourceFile = $sourceRel;
            $dto->lutFile = $lutRel;
            $dto->supportsAmount = true;
            $dto->defaultAmount = 100;
            $dto->enabled = true;
            $dto->exactParams = $xmpMeta['exact'];
            $dto->approximatedParams = $xmpMeta['approx'];
            $dto->unsupportedParams = $xmpMeta['unsupported'];

            $this->presets[$dto->id] = $dto;
            $this->logger->info("Auto-discovered and registered new preset: {$displayName} ({$slug})");
            $dirty = true;
        }

        if ($dirty) {
            $this->savePresets();
        }
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

                // Film lifted black floor (matte blacks look)
                if (!empty($xmpParams['_ToneCurvePV2012']) && is_array($xmpParams['_ToneCurvePV2012'])) {
                    $firstPoint = trim($xmpParams['_ToneCurvePV2012'][0]);
                    $parts = explode(',', $firstPoint);
                    if (isset($parts[1])) {
                        $blackFloor = (float) trim($parts[1]);
                        if ($blackFloor > 0) {
                            $quantum = \Imagick::getQuantumRange()['quantumRangeLong'];
                            $liftVal = ($blackFloor / 255.0) * $quantum * 0.75;
                            $lut->evaluateImage(\Imagick::EVALUATE_ADD, $liftVal);
                        }
                    }
                } elseif (((float) ($xmpParams['Blacks2012'] ?? 0)) > 10) {
                    $quantum = \Imagick::getQuantumRange()['quantumRangeLong'];
                    $liftVal = (((float) $xmpParams['Blacks2012']) / 100.0) * 0.05 * $quantum;
                    $lut->evaluateImage(\Imagick::EVALUATE_ADD, $liftVal);
                }

                // Subtle split-toning warmth / cool balance
                $splitShadowHue = (float) ($xmpParams['SplitToningShadowHue'] ?? 0);
                $splitShadowSat = (float) ($xmpParams['SplitToningShadowSaturation'] ?? 0);
                $splitHighlightHue = (float) ($xmpParams['SplitToningHighlightHue'] ?? 0);
                $splitHighlightSat = (float) ($xmpParams['SplitToningHighlightSaturation'] ?? 0);

                if ($splitHighlightHue >= 15 && $splitHighlightHue <= 45 && $splitHighlightSat > 0) {
                    $lut->levelImage(0, 0.98, \Imagick::getQuantumRange()['quantumRangeLong'], \Imagick::CHANNEL_RED);
                }
                if ($splitShadowHue >= 140 && $splitShadowHue <= 190 && $splitShadowSat > 5) {
                    $lut->levelImage(0, 1.02, \Imagick::getQuantumRange()['quantumRangeLong'], \Imagick::CHANNEL_BLUE);
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

        // Extract optional preset name from <crs:Name>
        if (preg_match('/<crs:Name>\s*<rdf:Alt>\s*<rdf:li[^>]*>([^<]+)<\/rdf:li>/i', $content, $m)) {
            $params['_Name'] = trim($m[1]);
        }
        // Extract tone curve points if present
        if (preg_match('/<crs:ToneCurvePV2012>\s*<rdf:Seq>(.*?)<\/rdf:Seq>/is', $content, $m)) {
            preg_match_all('/<rdf:li>([^<]+)<\/rdf:li>/i', $m[1], $curveMatches);
            if (!empty($curveMatches[1])) {
                $params['_ToneCurvePV2012'] = $curveMatches[1];
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
     * Slugify string with Vietnamese diacritics mapping
     */
    public static function slugify(string $text): string
    {
        $vietnameseMap = [
            'à'=>'a','á'=>'a','ả'=>'a','ã'=>'a','ạ'=>'a','ă'=>'a','ằ'=>'a','ắ'=>'a','ẳ'=>'a','ẵ'=>'a','ặ'=>'a',
            'â'=>'a','ầ'=>'a','ấ'=>'a','ẩ'=>'a','ẫ'=>'a','ậ'=>'a',
            'è'=>'e','é'=>'e','ẻ'=>'e','ẽ'=>'e','ẹ'=>'e','ê'=>'e','ề'=>'e','ế'=>'e','ể'=>'e','ễ'=>'e','ệ'=>'e',
            'ì'=>'i','í'=>'i','ỉ'=>'i','ĩ'=>'i','ị'=>'i',
            'ò'=>'o','ó'=>'o','ỏ'=>'o','õ'=>'o','ọ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ổ'=>'o','ỗ'=>'o','ộ'=>'o',
            'ơ'=>'o','ờ'=>'o','ớ'=>'o','ở'=>'o','ỡ'=>'o','ợ'=>'o',
            'ù'=>'u','ú'=>'u','ủ'=>'u','ũ'=>'u','ụ'=>'u','ư'=>'u','ừ'=>'u','ứ'=>'u','ử'=>'u','ữ'=>'u','ự'=>'u',
            'ỳ'=>'y','ý'=>'y','ỷ'=>'y','ỹ'=>'y','ỵ'=>'y',
            'đ'=>'d',
            'À'=>'a','Á'=>'a','Ả'=>'a','Ã'=>'a','Ạ'=>'a','Ă'=>'a','Ằ'=>'a','Ắ'=>'a','Ẳ'=>'a','Ẵ'=>'a','Ặ'=>'a',
            'Â'=>'a','Ầ'=>'a','Ấ'=>'a','Ẩ'=>'a','Ẫ'=>'a','Ậ'=>'a',
            'È'=>'e','É'=>'e','Ẻ'=>'e','Ẽ'=>'e','Ẹ'=>'e','Ê'=>'e','Ề'=>'e','Ế'=>'e','Ể'=>'e','Ễ'=>'e','Ệ'=>'e',
            'Ì'=>'i','Í'=>'i','Ỉ'=>'i','Ĩ'=>'i','Ị'=>'i',
            'Ò'=>'o','Ó'=>'o','Ỏ'=>'o','Õ'=>'o','Ọ'=>'o','Ô'=>'o','Ồ'=>'o','Ố'=>'o','Ổ'=>'o','Ỗ'=>'o','Ộ'=>'o',
            'Ơ'=>'o','Ờ'=>'o','Ớ'=>'o','Ở'=>'o','Ỡ'=>'o','Ợ'=>'o',
            'Ù'=>'u','Ú'=>'u','Ủ'=>'u','Ũ'=>'u','Ụ'=>'u','Ư'=>'u','Ừ'=>'u','Ứ'=>'u','Ử'=>'u','Ữ'=>'u','Ự'=>'u',
            'Ỳ'=>'y','Ý'=>'y','Ỷ'=>'y','Ỹ'=>'y','Ỵ'=>'y',
            'Đ'=>'d',
        ];
        $text = strtr($text, $vietnameseMap);
        $text = preg_replace('/[^a-zA-Z0-9\s-]/', '', strtolower($text)) ?? '';
        $text = preg_replace('/[\s-]+/', '-', trim($text)) ?? '';
        return trim($text, '-');
    }

    /**
     * Format title with capital letters for words
     */
    public static function formatTitle(string $text): string
    {
        $words = explode(' ', trim($text));
        $titleWords = array_map(function($w) {
            if ($w === '') {
                return '';
            }
            return mb_convert_case(mb_substr($w, 0, 1, 'UTF-8'), MB_CASE_UPPER, 'UTF-8') . mb_substr($w, 1, null, 'UTF-8');
        }, $words);
        return implode(' ', $titleWords);
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
