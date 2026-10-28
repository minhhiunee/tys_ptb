<?php

declare(strict_types=1);

namespace Photobooth\Camera;

use Photobooth\Image;
use Photobooth\Service\LoggerService;
use Photobooth\Utility\PathUtility;
use Psr\Log\LoggerInterface;

abstract class AbstractCameraProvider implements CameraProviderInterface
{
    protected LoggerInterface $logger;
    protected ?string $lastError = null;
    protected string $lockFile;
    /** @var resource|null */
    protected $lockHandle = null;

    public function __construct()
    {
        $this->logger = LoggerService::getInstance()->getLogger('main');
        $lockDir = PathUtility::getAbsolutePath('var/run');
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0755, true);
        }
        $this->lockFile = $lockDir . DIRECTORY_SEPARATOR . 'camera_' . $this->getId() . '.lock';
    }

    /**
     * Acquire exclusive process lock to prevent multi-process USB/Camera conflict
     */
    protected function acquireDeviceLock(int $timeoutMs = 5000): bool
    {
        $start = microtime(true);
        $handle = @fopen($this->lockFile, 'c+');
        if ($handle === false) {
            return true; // If filesystem doesn't allow lock file, proceed gracefully
        }

        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (((microtime(true) - $start) * 1000) >= $timeoutMs) {
                fclose($handle);
                $this->lastError = 'Camera device is busy (locked by another process).';
                $this->logger->warning($this->lastError, ['provider' => $this->getId()]);
                return false;
            }
            usleep(100000); // Wait 100ms before retrying
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid() . ':' . time());
        fflush($handle);
        $this->lockHandle = $handle;
        return true;
    }

    /**
     * Release process lock
     */
    protected function releaseDeviceLock(): void
    {
        if (is_resource($this->lockHandle)) {
            @flock($this->lockHandle, LOCK_UN);
            @fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    /**
     * Check if device is currently locked by another process
     */
    public function isDeviceLocked(): bool
    {
        if (!file_exists($this->lockFile)) {
            return false;
        }
        $handle = @fopen($this->lockFile, 'r');
        if ($handle === false) {
            return false;
        }
        $canLock = flock($handle, LOCK_EX | LOCK_NB);
        if ($canLock) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        return !$canLock;
    }

    /**
     * Apply horizontal/vertical flip to captured image if requested
     */
    protected function applyFlipIfNeeded(string $filePath, string $flipMode): void
    {
        if ($flipMode === 'off' || $flipMode === '' || !file_exists($filePath)) {
            return;
        }

        try {
            $imageHandler = new Image();
            $im = $imageHandler->createFromImage($filePath);
            if (!$im instanceof \GdImage) {
                return;
            }
            $imageHandler->jpegQuality = 100;
            switch ($flipMode) {
                case 'flip-horizontal':
                    imageflip($im, IMG_FLIP_HORIZONTAL);
                    break;
                case 'flip-vertical':
                    imageflip($im, IMG_FLIP_VERTICAL);
                    break;
                case 'flip-both':
                    imageflip($im, IMG_FLIP_BOTH);
                    break;
            }
            $imageHandler->saveJpeg($im, $filePath);
            unset($im);
        } catch (\Throwable $e) {
            $this->logger->error('Flip failed: ' . $e->getMessage());
        }
    }
}

