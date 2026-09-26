<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CacheService
{
    public const BASE_SETTINGS = 'base_settings';
    public const BASE_MENUS    = 'base_menus';

    public const TTL_ONE_MONTH = 2592000; // 30 روز

    public static function remember(string $key, int $ttl, \Closure $callback)
    {
        try {
            return Cache::remember($key, $ttl, $callback);
        } catch (\Throwable $e) {
            Log::warning("Cache failed [{$key}]: " . $e->getMessage());
            return $callback();
        }
    }

    public static function forget(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (\Throwable $e) {
            Log::warning("Cache forget failed [{$key}]: " . $e->getMessage());
        }
    }

    public static function forgetMenus(): void
    {
        self::forget(self::BASE_MENUS);
    }

    public static function forgetSettings(): void
    {
        self::forget(self::BASE_SETTINGS);
    }
}
