<?php

/**
 * Resilient Multi-Tier Caching for Fare Settings
 * Tier 1: In-memory static array (Zero DB / disk hit within same PHP lifecycle)
 * Tier 2: Atomic file cache in sys_get_temp_dir() with TTL
 */
class FareCache {
    private static array $memoryCache = [];
    private static string $cacheDir = '';

    private static function getDir(): string {
        if (empty(self::$cacheDir)) {
            self::$cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rentox_cache';
            if (!is_dir(self::$cacheDir)) {
                @mkdir(self::$cacheDir, 0777, true);
            }
        }
        return self::$cacheDir;
    }

    private static function getFilePath(string $key): string {
        $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
        return self::getDir() . DIRECTORY_SEPARATOR . "fare_{$safeKey}.json";
    }

    public static function get(string $key) {
        // Check Memory Cache
        if (isset(self::$memoryCache[$key])) {
            return self::$memoryCache[$key];
        }

        // Check File Cache
        $file = self::getFilePath($key);
        if (file_exists($file)) {
            $data = @json_decode(@file_get_contents($file), true);
            if (is_array($data) && isset($data['expires_at']) && $data['expires_at'] > time()) {
                self::$memoryCache[$key] = $data['payload'];
                return $data['payload'];
            }
            @unlink($file);
        }

        return null;
    }

    public static function set(string $key, $value, int $ttlSeconds = 300): void {
        self::$memoryCache[$key] = $value;

        $file = self::getFilePath($key);
        $data = [
            'expires_at' => time() + $ttlSeconds,
            'payload'    => $value,
        ];
        @file_put_contents($file, json_encode($data), LOCK_EX);
    }

    public static function invalidate(string $key): void {
        unset(self::$memoryCache[$key]);
        $file = self::getFilePath($key);
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    public static function flushAll(): void {
        self::$memoryCache = [];
        $dir = self::getDir();
        if (is_dir($dir)) {
            $files = glob($dir . DIRECTORY_SEPARATOR . 'fare_*.json');
            if ($files) {
                foreach ($files as $f) {
                    @unlink($f);
                }
            }
        }
    }
}
