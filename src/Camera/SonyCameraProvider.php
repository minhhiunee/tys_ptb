<?php

declare(strict_types=1);

namespace Photobooth\Camera;

class SonyCameraProvider extends AbstractCameraProvider
{
    protected string $targetModel;
    protected string $customCaptureCmd;
    protected string $detectedModel = 'Sony Alpha (ILCE-6400 / ZV-E10)';
    protected bool $isConnected = false;

    public function __construct(string $targetModel = 'auto', string $customCaptureCmd = '')
    {
        parent::__construct();
        $this->targetModel = $targetModel;
        $this->customCaptureCmd = $customCaptureCmd;
    }

    public function getId(): string
    {
        return 'sony';
    }

    public function getName(): string
    {
        return $this->detectedModel;
    }

    public function isMock(): bool
    {
        return false;
    }

    public function connect(): bool
    {
        if (!$this->acquireDeviceLock(4000)) {
            return false;
        }

        try {
            $output = [];
            $returnVar = 0;
            @exec('gphoto2 --auto-detect 2>&1', $output, $returnVar);
            $raw = implode("\n", $output);

            if (preg_match('/(Sony\s+[^\r\n]+|ILCE-6400|ZV-E10|Alpha-A6400)/i', $raw, $m)) {
                $this->detectedModel = trim($m[1]);
                $this->isConnected = true;
                $this->lastError = null;
                return true;
            }

            // If custom Windows CLI (e.g. Sony Remote CLI / digicamcontrol) is configured
            if ($this->customCaptureCmd !== '') {
                $this->isConnected = true;
                $this->lastError = null;
                return true;
            }

            $this->isConnected = false;
            $this->lastError = 'No Sony camera (α6400 / ZV-E10) detected over USB PTP.';
            return false;
        } finally {
            $this->releaseDeviceLock();
        }
    }

    public function reconnect(): bool
    {
        $this->logger->info('Attempting Sony camera USB PTP reset & reconnect...');
        $this->releaseDeviceLock();

        if ($this->acquireDeviceLock(4000)) {
            try {
                @exec('gphoto2 --reset 2>&1');
                usleep(500000); // Allow 500ms for Sony PTP interface to re-enumerate
            } finally {
                $this->releaseDeviceLock();
            }
        }

        return $this->connect();
    }

    public function disconnect(): bool
    {
        $this->isConnected = false;
        $this->releaseDeviceLock();
        return true;
    }

    public function getStatus(): array
    {
        $connected = $this->connect();
        return [
            'connected' => $connected,
            'provider' => $this->getId(),
            'model' => $this->detectedModel,
            'supported_targets' => ['Sony ILCE-6400 (α6400)', 'Sony ZV-E10'],
            'is_mock' => false,
            'live_view_supported' => true,
            'live_view_mode' => 'uvc_or_hdmi_capture',
            'last_error' => $this->lastError,
            'locked' => $this->isDeviceLocked(),
        ];
    }

    public function capture(string $targetFilePath, array $options = []): array
    {
        $startTime = microtime(true);

        if (!$this->acquireDeviceLock(8000)) {
            return [
                'success' => false,
                'file' => $targetFilePath,
                'capture_time_ms' => (int) round((microtime(true) - $startTime) * 1000),
                'provider' => $this->getId(),
                'is_mock' => false,
                'error' => $this->lastError ?? 'Sony camera device is busy.',
            ];
        }

        try {
            $dir = dirname($targetFilePath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            // Build capture command (custom command or optimized Sony PTP command)
            if (!empty($options['captureCmd'])) {
                $cmd = sprintf((string) $options['captureCmd'], escapeshellarg($targetFilePath));
            } elseif ($this->customCaptureCmd !== '') {
                $cmd = sprintf($this->customCaptureCmd, escapeshellarg($targetFilePath));
            } else {
                // Optimized gphoto2 command for Sony ILCE-6400 & ZV-E10 PC Remote Mode
                $cmd = sprintf(
                    'gphoto2 --capture-image-and-download --filename=%s --force-overwrite',
                    escapeshellarg($targetFilePath)
                );
            }

            $cmd .= ' 2>&1';
            $output = [];
            $returnCode = 0;
            exec($cmd, $output, $returnCode);

            // Auto-retry once with USB reset if Sony PTP reported busy/timeout
            if (($returnCode !== 0 || !file_exists($targetFilePath)) && str_contains($cmd, 'gphoto2')) {
                $this->logger->warning('Sony capture failed on attempt 1, resetting USB and retrying...', [
                    'cmd' => $cmd,
                    'output' => $output,
                ]);
                @exec('gphoto2 --reset 2>&1');
                usleep(400000);
                $output = [];
                exec($cmd, $output, $returnCode);
            }

            if ($returnCode !== 0 || !file_exists($targetFilePath) || filesize($targetFilePath) === 0) {
                $errMsg = 'Sony capture failed (exit ' . $returnCode . '): ' . implode(' | ', $output);
                throw new \RuntimeException($errMsg);
            }

            $flipMode = (string) ($options['flip'] ?? 'off');
            $this->applyFlipIfNeeded($targetFilePath, $flipMode);

            $elapsedMs = (int) round((microtime(true) - $startTime) * 1000);
            $this->lastError = null;
            $this->logger->info('SonyCameraProvider capture succeeded', [
                'model' => $this->detectedModel,
                'file' => basename($targetFilePath),
                'capture_time_ms' => $elapsedMs,
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
            $this->logger->error('SonyCameraProvider error: ' . $this->lastError);
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

