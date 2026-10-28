<?php

namespace Photobooth;

use Photobooth\Logger\NamedLogger;
use Photobooth\Service\CameraManagerService;
use Photobooth\Service\LoggerService;
use Photobooth\Utility\ImageUtility;

/**
 * Class PhotoboothCapture
 */
class PhotoboothCapture
{
    public string $style;
    public string $fileName;
    public string $tmpFile;
    public string $collageSubFile;
    public int $collageNumber = 0;
    public int $collageLimit = 1;
    public string $flipImage = 'off';
    public string $captureCmd;
    public NamedLogger $logger;
    public int $debugLevel = 1;
    public array $providerMeta = [];

    /**
     * PhotoboothCapture constructor.
     */
    public function __construct()
    {
        $this->logger = LoggerService::getInstance()->getLogger('main');
        $this->logger->debug(self::class);
    }

    /**
     * Capture a demo image via MockCameraProvider.
     */
    public function captureDemo(): void
    {
        $this->logger->debug('Capture Demo via MockCameraProvider');

        $provider = CameraManagerService::getInstance()->getActiveProvider(['provider' => 'mock']);
        $res = $provider->capture($this->tmpFile, [
            'collageNumber' => $this->collageNumber,
            'flip' => $this->flipImage,
            'style' => $this->style,
        ]);
        $this->providerMeta = $res;

        if (empty($res['success'])) {
            $err = $res['error'] ?? 'Failed to load demo image!';
            $this->logger->error($err);
            echo json_encode(['error' => $err]);
            die();
        }
    }

    /**
     * Capture an image from canvas data via WebcamProvider.
     * @param string $data
     */
    public function captureCanvas($data): void
    {
        $this->logger->debug('Capture Canvas via WebcamProvider');

        $provider = CameraManagerService::getInstance()->getActiveProvider(['provider' => 'webcam']);
        $res = $provider->capture($this->tmpFile, [
            'canvasData' => (string) $data,
            'flip' => $this->flipImage,
            'style' => $this->style,
        ]);
        $this->providerMeta = $res;

        if (empty($res['success'])) {
            $err = $res['error'] ?? 'Failed to capture webcam canvas';
            $this->logger->error($err);
            echo json_encode(['error' => $err]);
            die();
        }
    }

    /**
     * Capture an image or video using a command.
     */
    public function captureWithCmd(): void
    {
        $this->logger->debug('Capture with CMD', [
            'cmd' => $this->captureCmd,
            'tmpFile' => $this->tmpFile,
        ]);
        //gphoto must be executed in a dir with write permission for other commands we stay in the api dir
        if (substr($this->captureCmd, 0, strlen('gphoto')) === 'gphoto') {
            chdir(dirname($this->tmpFile));
        }
        $cmd = sprintf($this->captureCmd, $this->tmpFile);
        $cmd .= ' 2>&1'; //Redirect stderr to stdout, otherwise error messages get lost.

        exec($cmd, $output, $returnValue);

        if ($returnValue && ($this->debugLevel > 1 || $this->style === 'video')) {
            $data = [
                'error' => 'Capture command returned an error code.',
                'cmd' => $cmd,
                'returnValue' => $returnValue,
                'output' => $output,
            ];
            $this->logger->error('error', $data);
            if ($this->style === 'video') {
                echo json_encode($data);
                die();
            }
        }

        if ($this->style === 'video') {
            $i = 0;
            $processingTime = 300;
            while ($i < $processingTime) {
                if (file_exists($this->tmpFile)) {
                    break;
                } else {
                    $i++;
                    usleep(100000);
                }
            }
        }

        if (!file_exists($this->tmpFile)) {
            $data = [
                'error' => 'File was not created',
                'cmd' => $cmd,
                'returnValue' => $returnValue,
                'output' => $output,
            ];
            if ($this->style === 'video') {
                // remove all files that were created - all filenames start with the videos name
                exec('rm -f ' . $this->tmpFile . '*');
            }
            $this->logger->error('error', $data);
            echo json_encode($data);
            die();
        }
    }

    /**
     * Return information about the successful capture process
     */
    public function returnData(): array
    {
        if ($this->style === 'collage') {
            $data = [
                'success' => 'collage',
                'file' => $this->fileName,
                'collage_file' => $this->collageSubFile,
                'current' => $this->collageNumber,
                'limit' => $this->collageLimit,
                'provider' => $this->providerMeta['provider'] ?? 'cmd',
                'is_mock' => $this->providerMeta['is_mock'] ?? false,
                'capture_time_ms' => $this->providerMeta['capture_time_ms'] ?? 0,
            ];
        } else {
            $data = [
                'success' => $this->style,
                'file' => $this->fileName,
                'provider' => $this->providerMeta['provider'] ?? 'cmd',
                'is_mock' => $this->providerMeta['is_mock'] ?? false,
                'capture_time_ms' => $this->providerMeta['capture_time_ms'] ?? 0,
            ];
        }
        $this->logger->debug('returnData', $data);
        return $data;
    }
}
