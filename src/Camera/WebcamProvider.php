<?php

declare(strict_types=1);

namespace Photobooth\Camera;

class WebcamProvider extends AbstractCameraProvider
{
    public function getId(): string
    {
        return 'webcam';
    }

    public function getName(): string
    {
        return 'HTML5 / USB UVC Webcam';
    }

    public function isMock(): bool
    {
        return false;
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
            'is_mock' => false,
            'live_view_supported' => true,
            'live_view_mode' => 'device_cam',
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
                'capture_time_ms' => (int) round((microtime(true) - $startTime) * 1000),
                'provider' => $this->getId(),
                'is_mock' => false,
                'error' => $this->lastError ?? 'Webcam lock timeout',
            ];
        }

        try {
            $canvasData = (string) ($options['canvasData'] ?? '');
            if ($canvasData === '' || !str_contains($canvasData, ',')) {
                throw new \RuntimeException('No valid webcam canvas frame received. Check browser camera permission or connection.');
            }

            [, $encoded] = explode(',', $canvasData, 2);
            $binary = base64_decode($encoded, true);
            if ($binary === false || strlen($binary) < 256) {
                throw new \RuntimeException('Corrupted or empty webcam image buffer.');
            }

            $dir = dirname($targetFilePath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            if (file_put_contents($targetFilePath, $binary) === false) {
                throw new \RuntimeException('Failed to write webcam image to disk.');
            }

            // Verify saved file is a valid readable image
            $imgInfo = @getimagesize($targetFilePath);
            if ($imgInfo === false || $imgInfo[0] < 10 || $imgInfo[1] < 10) {
                @unlink($targetFilePath);
                throw new \RuntimeException('Captured webcam file is not a valid image.');
            }

            $flipMode = (string) ($options['flip'] ?? 'off');
            $this->applyFlipIfNeeded($targetFilePath, $flipMode);

            $elapsedMs = (int) round((microtime(true) - $startTime) * 1000);
            $this->logger->debug('WebcamProvider capture succeeded', [
                'file' => basename($targetFilePath),
                'capture_time_ms' => $elapsedMs,
                'resolution' => $imgInfo[0] . 'x' . $imgInfo[1],
            ]);

            return [
                'success' => true,
                'file' => $targetFilePath,
                'capture_time_ms' => $elapsedMs,
                'provider' => $this->getId(),
                'is_mock' => false,
            ];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->logger->error('WebcamProvider error: ' . $this->lastError);
            return [
                'success' => false,
                'file' => $targetFilePath,
                'capture_time_ms' => (int) round((microtime(true) - $startTime) * 1000),
                'provider' => $this->getId(),
                'is_mock' => false,
                'error' => $this->lastError,
            ];
        } finally {
            $this->releaseDeviceLock();
        }
    }
}

