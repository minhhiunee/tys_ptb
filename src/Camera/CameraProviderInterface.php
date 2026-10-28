<?php

declare(strict_types=1);

namespace Photobooth\Camera;

interface CameraProviderInterface
{
    /**
     * Unique provider identifier (e.g. 'webcam', 'sony_a6400', 'sony_zve10', 'gphoto2', 'mock')
     */
    public function getId(): string;

    /**
     * Human-readable provider / device name
     */
    public function getName(): string;

    /**
     * Whether this provider is a simulated mock device
     */
    public function isMock(): bool;

    /**
     * Initialize connection to the camera hardware
     */
    public function connect(): bool;

    /**
     * Attempt automatic reconnection if camera was disconnected or locked
     */
    public function reconnect(): bool;

    /**
     * Disconnect and release camera resources
     */
    public function disconnect(): bool;

    /**
     * Get detailed hardware & connection status
     *
     * @return array{
     *   connected: bool,
     *   provider: string,
     *   model: string,
     *   is_mock: bool,
     *   live_view_supported: bool,
     *   live_view_mode: string,
     *   battery?: string,
     *   last_error?: string|null,
     *   locked?: bool
     * }
     */
    public function getStatus(): array;

    /**
     * Capture a photo and store it at $targetFilePath
     *
     * @param string $targetFilePath Destination path for the captured image
     * @param array $options Additional capture options (e.g. canvasData, flip, style)
     * @return array{
     *   success: bool,
     *   file: string,
     *   capture_time_ms: int,
     *   provider: string,
     *   is_mock: bool,
     *   error?: string
     * }
     */
    public function capture(string $targetFilePath, array $options = []): array;
}

