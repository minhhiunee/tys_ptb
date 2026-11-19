<?php

namespace Photobooth\Asset\VersionStrategy;

use DateTime;
use Photobooth\Utility\PathUtility;
use Symfony\Component\Asset\VersionStrategy\VersionStrategyInterface;

class AutoVersionStrategy implements VersionStrategyInterface
{
    private DateTime $requestTime;
    private ?array $revisionData = null;
    private ?string $packageLockHash = null;
    private bool $packageLockLoaded = false;
    /** @var array<string, string> */
    private array $versionCache = [];

    public function __construct()
    {
        $this->requestTime = new DateTime();
    }

    private function getRevisionData(): array
    {
        if ($this->revisionData !== null) {
            return $this->revisionData;
        }

        $this->revisionData = [];
        $manifestPath = PathUtility::getAbsolutePath('resources/revisions.json');
        if (is_file($manifestPath)) {
            try {
                $this->revisionData = json_decode((string) file_get_contents($manifestPath), true, flags: \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                // fall back to unversioned files
            }
        }

        return $this->revisionData;
    }

    private function getPackageLockHash(): ?string
    {
        if ($this->packageLockLoaded) {
            return $this->packageLockHash;
        }

        $this->packageLockLoaded = true;
        $packageLockPath = PathUtility::getAbsolutePath('package-lock.json');
        if (is_file($packageLockPath)) {
            $mtime = @filemtime($packageLockPath);
            $size = @filesize($packageLockPath);
            $this->packageLockHash = sha1($mtime . ':' . $size);
        }

        return $this->packageLockHash;
    }

    public function getVersion(string $path): string
    {
        if (isset($this->versionCache[$path])) {
            return $this->versionCache[$path];
        }

        // all api calls should not be cached at all
        if (str_starts_with($path, 'api')) {
            return $this->versionCache[$path] = $this->requestTime->format('YmdHis');
        }

        // node modules should only be updated when
        // the locked packages are changed
        if (str_starts_with($path, 'node_modules')) {
            return $this->versionCache[$path] = ($this->getPackageLockHash() ?? '');
        }

        // always check private files for changed
        // content to avoid caching issues
        if (str_starts_with($path, 'private')) {
            $absolutePath = PathUtility::getAbsolutePath($path);
            if (is_file($absolutePath)) {
                $mtime = @filemtime($absolutePath);
                $size = @filesize($absolutePath);
                if ($mtime !== false) {
                    return $this->versionCache[$path] = $mtime . '-' . ($size ?: 0);
                }
            }
        }

        // Always use live filemtime for local files when present so JS/CSS updates are never stale in browser cache
        $absolutePath = PathUtility::getAbsolutePath($path);
        if (is_file($absolutePath)) {
            $mtime = @filemtime($absolutePath);
            if ($mtime !== false) {
                return $this->versionCache[$path] = (string) $mtime;
            }
        }

        // revision data contains a hash of the
        // current file contents, this one is
        // applied when a hash exists.
        $revisions = $this->getRevisionData();
        if ($revisions[$path] ?? null) {
            return $this->versionCache[$path] = $revisions[$path];
        }

        // all other resources are considered not
        // versioned, no version will be generated
        return $this->versionCache[$path] = '';
    }

    public function applyVersion(string $path): string
    {
        $version = $this->getVersion($path);
        $path = PathUtility::getPublicPath($path);
        if ($version !== '') {
            return sprintf('%s?v=%s', $path, $version);
        }

        return $path;
    }
}
