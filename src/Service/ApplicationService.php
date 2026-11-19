<?php

namespace Photobooth\Service;

use Photobooth\Utility\PathUtility;

class ApplicationService
{
    private const DEV_PLACEHOLDER_VERSION = '4.99.0';

    protected string $version;

    public function __construct()
    {
        $this->version = $this->calculatePhotoboothVersion();
    }

    public function getTitle(): string
    {
        return 'Photobooth';
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    protected function calculatePhotoboothVersion(): string
    {
        $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'photobooth_version.cache';
        if (is_file($cacheFile)) {
            $cached = @file_get_contents($cacheFile);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $packageJsonPath = PathUtility::getRootPath() . DIRECTORY_SEPARATOR . 'package.json';
        if (!is_file($packageJsonPath)) {
            throw new \Exception('Package file not found.');
        }
        $packageContent = file_get_contents($packageJsonPath);
        if ($packageContent === false) {
            throw new \Exception('Error loading package file: ' . $packageJsonPath);
        }
        $package = json_decode($packageContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Error decoding package file: ' . json_last_error_msg());
        }

        $gitVersion = '';
        $version = '';
        if ($package['version'] === self::DEV_PLACEHOLDER_VERSION) {
            $gitVersion = $this->getGitVersion();
            $version = self::DEV_PLACEHOLDER_VERSION;
        } else {
            $version = $package['version'] ?? 'unknown';
        }

        $finalVersion = $gitVersion ? $version . ' ' . $gitVersion : $version;
        @file_put_contents($cacheFile, $finalVersion);

        return $finalVersion;
    }

    private function getGitVersion(): string
    {
        $gitDir = PathUtility::getRootPath() . DIRECTORY_SEPARATOR . '.git';
        if (!is_dir($gitDir) && !is_file($gitDir)) {
            return 'dev';
        }

        $hash = trim(shell_exec('git rev-parse --short HEAD 2>/dev/null') ?: '');
        $date = trim(shell_exec('git log -1 --format=%cd --date=short 2>/dev/null') ?: '');

        if ($hash && $date) {
            return sprintf('%s-dev %s', $hash, $date);
        }

        return $hash ? sprintf('%s-dev', $hash) : 'dev';
    }

    /**
     * Check whether an update to the photobooth software is available.
     */
    public function checkUpdate(): bool
    {
        return false;
    }

    public function getLatestRelease(): string
    {
        return $this->getVersion();
    }

    public static function getInstance(): self
    {
        if (!isset($GLOBALS[self::class])) {
            $GLOBALS[self::class] = new self();
        }

        return $GLOBALS[self::class];
    }
}
