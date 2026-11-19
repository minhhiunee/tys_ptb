<?php

declare(strict_types=1);

namespace Photobooth\Service;

/**
 * Decides how color grading is handled:
 *  - Mode 1 (default): a base preset is baked into every shot (and its time-lapse frames) right after capture.
 *  - Mode 2 (`color_workflow.interactive_presets`): shots stay pristine and guests pick presets on the result screen.
 */
class ColorWorkflowService
{
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    protected function getSettings(): array
    {
        $config = ConfigurationService::getInstance()->getConfiguration();
        $section = $config['color_workflow'] ?? [];
        return is_array($section) ? $section : [];
    }

    public function isInteractive(): bool
    {
        return (bool) ($this->getSettings()['interactive_presets'] ?? false);
    }

    public function isCaptureOneEnabled(): bool
    {
        return CaptureOneIntegrationService::getInstance()->isEnabled();
    }

    /**
     * Base preset ID for Mode 1. Returns 'original' when no usable preset is configured.
     */
    public function getBasePresetId(): string
    {
        if ($this->isCaptureOneEnabled()) {
            $c1Style = CaptureOneIntegrationService::getInstance()->getDefaultStyle();
            if ($c1Style !== '' && $c1Style !== 'none') {
                return $c1Style;
            }
        }
        $id = trim((string) ($this->getSettings()['base_preset'] ?? 'clean-portrait'));
        if ($id === '' || $id === 'none' || $id === 'original') {
            return 'original';
        }
        $preset = PresetService::getInstance()->getPresetById($id);
        if ($preset === null || !$preset->enabled) {
            return 'original';
        }
        return $id;
    }

    public function getBaseAmount(): int
    {
        $amount = (int) ($this->getSettings()['base_amount'] ?? 100);
        return max(0, min(100, $amount));
    }

    /**
     * True when captured shots must be graded at ingest time (Mode 1 with a real base preset, or Capture One active).
     */
    public function shouldGradeAtIngest(): bool
    {
        if ($this->isCaptureOneEnabled()) {
            return true;
        }
        return !$this->isInteractive()
            && $this->getBasePresetId() !== 'original'
            && $this->getBaseAmount() > 0;
    }

    /**
     * Grade a primary captured shot using Capture One (when enabled) or the internal LUT engine.
     *
     * @return array<string, mixed>
     */
    public function processPrimaryCapture(string $shotPath, string $sessionId, int $poseIndex = 0): array
    {
        if ($this->isCaptureOneEnabled()) {
            $c1 = CaptureOneIntegrationService::getInstance();
            return $c1->processCapturedPose($shotPath, $sessionId, $poseIndex, [
                'style' => $this->getBasePresetId(),
                'amount' => $this->getBaseAmount(),
            ]);
        }

        return $this->gradeFilesInPlace([$shotPath]) + [
            'provider' => 'internal_lut',
            'style' => $this->getBasePresetId(),
            'fallback_used' => false,
        ];
    }

    /**
     * Grade the given files in place with the base preset (single process => LUT is decoded once).
     *
     * @param string[] $paths
     * @return array{graded:int, ms:float}
     */
    public function gradeFilesInPlace(array $paths, int $jpegQuality = 94): array
    {
        $start = hrtime(true);
        $graded = 0;
        if ($this->isCaptureOneEnabled() || !$this->shouldGradeAtIngest()) {
            return ['graded' => 0, 'ms' => 0.0];
        }

        $presetService = PresetService::getInstance();
        $presetId = $this->getBasePresetId();
        $amount = $this->getBaseAmount();

        foreach ($paths as $path) {
            if (!is_string($path) || !is_file($path) || filesize($path) === 0) {
                continue;
            }
            // Temp name must not match the `tl_<session>_p*.jpg` / `<session>*.jpg` globs used by the pipeline
            $tmp = dirname($path) . DIRECTORY_SEPARATOR . 'gradetmp_' . uniqid('', true) . '.jpg';
            // Small time-lapse frames use a lighter JPEG quality; full-size shots keep $jpegQuality
            $quality = (filesize($path) < 120 * 1024) ? 85 : $jpegQuality;
            if ($presetService->applyPreset($path, $tmp, $presetId, $amount, null, $quality) && is_file($tmp) && filesize($tmp) > 0) {
                @rename($tmp, $path);
                $graded++;
            } else {
                @unlink($tmp);
            }
        }

        return ['graded' => $graded, 'ms' => round((hrtime(true) - $start) / 1e6, 1)];
    }

    /**
     * Mode 2 per-shot background warm-up: touch every enabled preset LUT so the first interactive
     * preview/bake on the result screen reads them from the OS file cache.
     */
    public function warmUpPresets(): int
    {
        if ($this->isCaptureOneEnabled() || !$this->isInteractive()) {
            return 0;
        }

        $warmed = 0;
        foreach (PresetService::getInstance()->getEnabledPresets() as $preset) {
            if (!empty($preset->lutFile)) {
                $abs = \Photobooth\Utility\PathUtility::getAbsolutePath($preset->lutFile);
                if (is_file($abs)) {
                    @file_get_contents($abs);
                    $warmed++;
                }
            }
        }
        return $warmed;
    }
}
