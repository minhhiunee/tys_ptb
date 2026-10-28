<?php

declare(strict_types=1);

namespace Photobooth\Camera;

use Photobooth\Image;
use Photobooth\Utility\ImageUtility;

class MockCameraProvider extends AbstractCameraProvider
{
    public function getId(): string
    {
        return 'mock';
    }

    public function getName(): string
    {
        return 'Mock DSLR Simulator (Workflow Test Mode — Not Hardware Proof)';
    }

    public function isMock(): bool
    {
        return true;
    }

    public function connect(): bool
    {
        $this->lastError = null;
        return true;
    }

    public function reconnect(): bool
    {
        $this->releaseDeviceLock();
        $this->lastError = null;
        return true;
    }

    public function disconnect(): bool
    {
        $this->releaseDeviceLock();
        return true;
    }

    public function getStatus(): array
    {
        return [
            'connected' => true,
            'provider' => $this->getId(),
            'model' => $this->getName(),
            'is_mock' => true,
            'notice' => 'Simulated camera provider for workflow testing only. Does not verify physical Sony/DSLR hardware compatibility.',
            'live_view_supported' => true,
            'live_view_mode' => 'demo_or_webcam',
            'last_error' => $this->lastError,
            'locked' => $this->isDeviceLocked(),
        ];
    }

    public function capture(string $targetFilePath, array $options = []): array
    {
        $startTime = microtime(true);

        if (!$this->acquireDeviceLock(3000)) {
            return [
                'success' => false,
                'file' => $targetFilePath,
                'capture_time_ms' => 0,
                'provider' => $this->getId(),
                'is_mock' => true,
                'error' => 'Mock camera device lock busy',
            ];
        }

        try {
            $dir = dirname($targetFilePath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $demoImages = [];
            try {
                $demoImages = ImageUtility::getDemoImages();
            } catch (\Throwable) {
                $demoImages = [];
            }

            if (!empty($demoImages)) {
                // Pick deterministically by collage pose index if provided, or randomly
                $poseIdx = isset($options['collageNumber']) ? (int) $options['collageNumber'] : array_rand($demoImages);
                $chosen = $demoImages[$poseIdx % count($demoImages)];
                $ext = strtolower(pathinfo($chosen, PATHINFO_EXTENSION));

                if (in_array($ext, ['jpg', 'jpeg'], true)) {
                    copy($chosen, $targetFilePath);
                } else {
                    $imageHandler = new Image();
                    $imageHandler->jpegQuality = 100;
                    $res = $imageHandler->createFromImage($chosen);
                    if ($res instanceof \GdImage) {
                        $imageHandler->saveJpeg($res, $targetFilePath);
                        unset($res);
                    }
                }
            }

            // If no demo image was copied, synthesize a clean test frame with pose number
            if (!file_exists($targetFilePath) || filesize($targetFilePath) === 0) {
                $this->generateSyntheticFrame($targetFilePath, (int) ($options['collageNumber'] ?? 0));
            }

            $flipMode = (string) ($options['flip'] ?? 'off');
            $this->applyFlipIfNeeded($targetFilePath, $flipMode);

            $elapsedMs = (int) round((microtime(true) - $startTime) * 1000);
            return [
                'success' => true,
                'file' => $targetFilePath,
                'capture_time_ms' => $elapsedMs,
                'provider' => $this->getId(),
                'is_mock' => true,
            ];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [
                'success' => false,
                'file' => $targetFilePath,
                'capture_time_ms' => (int) round((microtime(true) - $startTime) * 1000),
                'provider' => $this->getId(),
                'is_mock' => true,
                'error' => $this->lastError,
            ];
        } finally {
            $this->releaseDeviceLock();
        }
    }

    protected function generateSyntheticFrame(string $targetFilePath, int $poseNumber): void
    {
        $w = 1280;
        $h = 960;
        $im = imagecreatetruecolor($w, $h);
        if (!$im) {
            throw new \RuntimeException('Failed to allocate synthetic image.');
        }

        $palettes = [
            [[37, 99, 235], [14, 165, 233]],
            [[16, 185, 129], [5, 150, 105]],
            [[124, 58, 237], [219, 39, 119]],
            [[245, 158, 11], [239, 68, 68]],
        ];
        $pal = $palettes[$poseNumber % count($palettes)];
        $bg = imagecolorallocate($im, $pal[0][0], $pal[0][1], $pal[0][2]);
        $accent = imagecolorallocate($im, $pal[1][0], $pal[1][1], $pal[1][2]);
        $white = imagecolorallocate($im, 255, 255, 255);

        imagefilledrectangle($im, 0, 0, $w, $h, $bg);
        imagefilledellipse($im, (int) ($w / 2), (int) ($h / 2), 460, 460, $accent);
        imagestring($im, 5, (int) ($w / 2) - 110, (int) ($h / 2) - 10, 'TYS PHOTOBOOTH POSE #' . ($poseNumber + 1), $white);

        imagejpeg($im, $targetFilePath, 95);
        imagedestroy($im);
    }
}

