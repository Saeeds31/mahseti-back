<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CacheService
{
    // ==================== Tags ====================

    public const TAG_CATEGORIES = 'categories';
    public const TAG_PRODUCTS   = 'products';
    public const TAG_BLOGS   = 'blogs';
    public const TAG_CITIES = 'cities';

    // ==================== Keys ====================

    public const BASE_SETTINGS = 'base_settings';
    public const BASE_MENUS    = 'base_menus';
    public const BASE_CATEGORIES    = 'base_categories';
    public const HOME_BANNER     = 'home_banner';
    public const HOME_SLIDER     = 'home_slider';
    public const PRODUCTS_PRICE     = 'products_price_range';
    public const BASE_PROVINCE     = 'provinces';
    // ==================== TTL ====================

    public const TTL_ONE_MONTH = 2592000; // 30 روز
    public const TTL_ONE_WEEK   = 604800;  // 7 روز
    public const TTL_ONE_DAY    = 86400;  // 1 روز
    public const TTL_ONE_HOUR   = 3600;  // 1 ساعت

    // ==================== Remember ====================
    public static function remember(string $key, int $ttl, \Closure $callback)
    {
        try {
            return Cache::remember($key, $ttl, $callback);
        } catch (\Throwable $e) {
            Log::warning("Cache failed [{$key}]: " . $e->getMessage());
            return $callback();
        }
    }
    public static function rememberWithTags(array $tags, string $key, int $ttl, \Closure $callback)
    {
        try {
            return Cache::tags($tags)->remember($key, $ttl, $callback);
        } catch (\Throwable $e) {
            Log::warning("Cache failed [{$key}]: " . $e->getMessage());
            return $callback();
        }
    }
    // ==================== Forget ====================
    public static function forget(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (\Throwable $e) {
            Log::warning("Cache forget failed [{$key}]: " . $e->getMessage());
        }
    }
    public static function flushTag(string $tag): void
    {
        try {
            Cache::tags([$tag])->flush();
        } catch (\Throwable $e) {
            Log::warning("Cache flush failed [{$tag}]: " . $e->getMessage());
        }
    }
    // ==================== Helpers ====================
    public static function forgetMenus(): void
    {
        self::forget(self::BASE_MENUS);
    }
    public static function forgetProvinces()
    {
        self::forget(self::BASE_PROVINCE);
    }
    public static function forgetCities(): void
    {
        self::flushTag(self::TAG_CITIES);
    }

    public static function forgetSettings(): void
    {
        self::forget(self::BASE_SETTINGS);
    }
    public static function forgetBanners(): void
    {
        self::forget(self::HOME_BANNER);
    }

    public static function forgetSliders(): void
    {
        self::forget(self::HOME_SLIDER);
    }

    public static function forgetPriceRange(): void
    {
        self::forget(self::PRODUCTS_PRICE);
    }
    public static function forgetBlogs(): void
    {
        self::flushTag(self::TAG_BLOGS);
    }
    public static function forgetCategories(): void
    {
        self::flushTag(self::TAG_CATEGORIES);
    }
    public static function forgetProducts(): void
    {
        self::flushTag(self::TAG_PRODUCTS);
    }
}
