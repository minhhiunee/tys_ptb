<?php

declare(strict_types=1);

namespace Photobooth\Service;

use Photobooth\Camera\CameraProviderInterface;
use Photobooth\Camera\MockCameraProvider;
use Photobooth\Camera\SonyCameraProvider;
use Photobooth\Camera\WebcamProvider;

class CameraManagerService
{
    private static ?self $instance = null;

    /** @var array<string, CameraProviderInterface> */
    protected array $providers = [];
    protected array $config;

    public function __construct()
    {
        $this->config = ConfigurationService::getInstance()->getConfiguration();
        $this->registerDefaultProviders();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    protected function registerDefaultProviders(): void
    {
        $this->registerProvider(new WebcamProvider());
        $this->registerProvider(new SonyCameraProvider(
            (string) ($this->config['camera']['sony_model'] ?? 'auto'),
            (string) ($this->config['commands']['take_picture'] ?? '')
        ));
        $this->registerProvider(new MockCameraProvider());
    }

    public function registerProvider(CameraProviderInterface $provider): void
    {
        $this->providers[$provider->getId()] = $provider;
    }

    /**
     * Resolve the active camera provider based on runtime request or configuration
     */
    public function getActiveProvider(array $runtimeContext = []): CameraProviderInterface
    {
        // Explicit provider override in config or request
        $configuredProvider = strtolower((string) ($runtimeContext['provider'] ?? $this->config['camera']['provider'] ?? ''));
        if ($configuredProvider !== '' && isset($this->providers[$configuredProvider])) {
            return $this->providers[$configuredProvider];
        }

        // Demo / Mock mode
        if (!empty($this->config['dev']['demo_images'])) {
            return $this->providers['mock'];
        }

        // Browser Webcam Canvas mode
        $hasCanvasPayload = !empty($runtimeContext['canvasData']);
        $isDeviceCamMode = ($this->config['preview']['mode'] ?? '') === 'device_cam' && !empty($this->config['preview']['camTakesPic']);
        if ($hasCanvasPayload || $isDeviceCamMode) {
            return $this->providers['webcam'];
        }

        // Default to Sony / DSLR provider if hardware capture command is used, or fall back to Mock if unconfigured
        $takePicCmd = trim((string) ($this->config['commands']['take_picture'] ?? ''));
        if ($takePicCmd !== '') {
            return $this->providers['sony'];
        }

        return $this->providers['mock'];
    }

    /**
     * Get status of active provider and list of all registered providers
     */
    public function getSystemCameraStatus(?string $providerId = null): array
    {
        $active = ($providerId !== null && isset($this->providers[$providerId]))
            ? $this->providers[$providerId]
            : $this->getActiveProvider();

        $available = [];
        foreach ($this->providers as $id => $provider) {
            $available[$id] = [
                'id' => $id,
                'name' => $provider->getName(),
                'is_mock' => $provider->isMock(),
            ];
        }

        return [
            'active_provider' => $active->getId(),
            'status' => $active->getStatus(),
            'available_providers' => $available,
        ];
    }
}

