<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Support;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use DomainException;

/**
 * Authoritative microscope purchase options.
 * Identified by SKU/slug — never by volatile product id.
 */
final class MicroscopePurchaseOptions
{
    public const SKU = 'SS-MICRO-001';

    public const SLUG = 'science-street-microscope-2';

    /** Legacy storefront slug that also maps to the microscope landing. */
    public const LEGACY_SLUG = 'science-street-microscope';

    public const LANDING_PATH = '/microscope-landing-page';

    public const OPTION_BOOK_LANGUAGE = 'book_language';

    public const BOOK_LANGUAGE_AR = 'ar';

    public const BOOK_LANGUAGE_EN = 'en';

    /**
     * @return list<string>
     */
    public static function allowedBookLanguages(): array
    {
        return [self::BOOK_LANGUAGE_AR, self::BOOK_LANGUAGE_EN];
    }

    public static function isMicroscopeProduct(?Product $product): bool
    {
        if ($product === null) {
            return false;
        }

        $sku = trim((string) $product->sku);
        $slug = trim((string) $product->slug);

        return $sku === self::SKU
            || $slug === self::SLUG
            || $slug === self::LEGACY_SLUG;
    }

    public static function displayBookLanguage(string $code, string $locale = 'ar'): string
    {
        return match ($code) {
            self::BOOK_LANGUAGE_AR => 'عربي',
            self::BOOK_LANGUAGE_EN => 'English',
            default => $locale === 'ar' ? 'غير محدد' : 'Unspecified',
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{book_language: string}
     */
    public static function validateAndNormalize(Product $product, array $input): array
    {
        if (! self::isMicroscopeProduct($product)) {
            return [];
        }

        $raw = $input[self::OPTION_BOOK_LANGUAGE] ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            throw new DomainException('Book language is required for the microscope.');
        }

        $value = strtolower(trim($raw));
        if (! in_array($value, self::allowedBookLanguages(), true)) {
            throw new DomainException('Invalid book language for the microscope.');
        }

        return [self::OPTION_BOOK_LANGUAGE => $value];
    }

    /**
     * Stable cart-line identity key for purchase options.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public static function optionsKey(?array $metadata): string
    {
        $lang = is_array($metadata)
            ? (string) ($metadata[self::OPTION_BOOK_LANGUAGE] ?? '')
            : '';

        if ($lang === '') {
            return '';
        }

        return self::OPTION_BOOK_LANGUAGE.':'.$lang;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public static function bookLanguageFromMetadata(?array $metadata): ?string
    {
        $lang = is_array($metadata) ? ($metadata[self::OPTION_BOOK_LANGUAGE] ?? null) : null;
        if (! is_string($lang)) {
            return null;
        }

        $lang = strtolower(trim($lang));

        return in_array($lang, self::allowedBookLanguages(), true) ? $lang : null;
    }
}
