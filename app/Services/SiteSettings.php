<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

final class SiteSettings
{
    public const CACHE_KEY = 'site_settings.payload';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'site_name_ar' => 'شارع العلوم',
            'site_name_en' => 'Science Street Lab',
            'tagline_ar' => 'نجعل العلوم جذابة وفي متناول الجيل القادم.',
            'tagline_en' => 'Making science fun and accessible for the next generation.',
            'contact_email' => 'hello@sciencestreetlab.com',
            'contact_phone' => '01004460433',
            'whatsapp' => '201000000000',
            'address' => 'Cairo, Egypt',
            'logo_url' => 'https://sciencestreetlab.com/wp-content/uploads/2026/01/4-2-e1768660519978.png',
            'facebook_url' => 'https://www.facebook.com/sciencestreetlab',
            'instagram_url' => 'https://www.instagram.com/sciencestreetlab',
            'youtube_url' => 'https://www.youtube.com/@sciencestreetlab',
            'tiktok_url' => 'https://www.tiktok.com/@sciencestreetlab',
            'linkedin_url' => 'https://www.linkedin.com/company/sciencestreetlab',
            'primary_color' => '#2828a0',
            'accent_color' => '#fcd500',
            'navbar_color' => '#fcd500',
            'navbar_text_color' => '#3030d0',
            'promo_banner_enabled' => true,
            'promo_banner' => 'خصم ٢٥٪ ليصبح سعره ٢٧٩٠ ج بدلا من 3720 بعد الخصم لفترة محدودة',
            'admin_brand_name' => 'Science Street Lab',
            'admin_primary_color' => '#2828a0',
            'admin_accent_color' => '#fcd500',
            'admin_layout' => 'container',
            'admin_sidebar_collapsible' => true,
            'admin_theme_mode' => 'system',
            'dashboard_heading' => 'Science Street Lab',
            'dashboard_subheading' => 'Store, learning, and competition overview',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        if (! self::tableReady()) {
            return self::defaults();
        }

        /** @var array<string, mixed> $payload */
        $payload = Cache::rememberForever(self::CACHE_KEY, function (): array {
            $record = SiteSetting::query()->first();

            if (! $record) {
                $record = SiteSetting::query()->create(['payload' => self::defaults()]);
            }

            return array_merge(self::defaults(), $record->payload ?? []);
        });

        return $payload;
    }

    public static function getString(string $key, ?string $default = null): string
    {
        $value = self::get()[$key] ?? $default ?? (self::defaults()[$key] ?? '');

        return is_string($value) ? $value : (string) $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function save(array $data): void
    {
        $payload = array_merge(self::defaults(), $data);

        $record = SiteSetting::query()->first();

        if ($record) {
            $record->update(['payload' => $payload]);
        } else {
            SiteSetting::query()->create(['payload' => $payload]);
        }

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Public website payload (no admin-only keys).
     *
     * @return array<string, mixed>
     */
    public static function public(): array
    {
        $all = self::get();

        return [
            'site_name_ar' => $all['site_name_ar'],
            'site_name_en' => $all['site_name_en'],
            'tagline_ar' => $all['tagline_ar'],
            'tagline_en' => $all['tagline_en'],
            'contact_email' => $all['contact_email'],
            'contact_phone' => $all['contact_phone'],
            'whatsapp' => $all['whatsapp'],
            'address' => $all['address'],
            'logo_url' => $all['logo_url'],
            'facebook_url' => $all['facebook_url'],
            'instagram_url' => $all['instagram_url'],
            'youtube_url' => $all['youtube_url'],
            'tiktok_url' => $all['tiktok_url'],
            'linkedin_url' => $all['linkedin_url'],
            'primary_color' => self::normalizeHex((string) $all['primary_color'], '#2828a0'),
            'accent_color' => self::normalizeHex((string) $all['accent_color'], '#fcd500'),
            'navbar_color' => self::normalizeHex((string) $all['navbar_color'], '#fcd500'),
            'navbar_text_color' => self::normalizeHex((string) $all['navbar_text_color'], '#3030d0'),
            'promo_banner_enabled' => (bool) $all['promo_banner_enabled'],
            'promo_banner' => $all['promo_banner'],
        ];
    }

    public static function normalizeHex(string $value, string $fallback): string
    {
        $value = trim($value);

        if ($value !== '' && ! str_starts_with($value, '#')) {
            $value = '#'.$value;
        }

        return preg_match('/^#[A-Fa-f0-9]{6}$/', $value) === 1 ? strtolower($value) : $fallback;
    }

    private static function tableReady(): bool
    {
        try {
            return Schema::hasTable('site_settings');
        } catch (\Throwable) {
            return false;
        }
    }
}
