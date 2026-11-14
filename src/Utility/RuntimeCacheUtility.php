<?php

namespace Photobooth\Utility;

class RuntimeCacheUtility
{
    private const CACHE_SUBDIR = 'photobooth_cache';

    public static function getCacheDirectory(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::CACHE_SUBDIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
            @chmod($dir, 0777);
        }

        return $dir;
    }

    public static function getFilePath(string $key, string $ext = 'ser'): string
    {
        return self::getCacheDirectory() . DIRECTORY_SEPARATOR . $key . '.' . $ext;
    }

    public static function get(string $key, string $fingerprint, string $ext = 'ser'): mixed
    {
        $path = self::getFilePath($key, $ext);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = $ext === 'json'
            ? json_decode($raw, true)
            : @unserialize($raw);

        if (
            !is_array($decoded)
            || ($decoded['fingerprint'] ?? null) !== $fingerprint
            || !array_key_exists('data', $decoded)
        ) {
            return null;
        }

        return $decoded['data'];
    }

    public static function set(string $key, string $fingerprint, mixed $data, string $ext = 'ser'): bool
    {
        $path = self::getFilePath($key, $ext);
        $payload = $ext === 'json'
            ? json_encode(['fingerprint' => $fingerprint, 'data' => $data])
            : serialize(['fingerprint' => $fingerprint, 'data' => $data]);

        if (!is_string($payload)) {
            return false;
        }

        $tmpPath = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmpPath, $payload, LOCK_EX) === false) {
            return false;
        }

        @chmod($tmpPath, 0666);
        if (!@rename($tmpPath, $path)) {
            @unlink($tmpPath);
            return false;
        }

        return true;
    }

    public static function delete(string $key, string $ext = 'ser'): void
    {
        $path = self::getFilePath($key, $ext);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

