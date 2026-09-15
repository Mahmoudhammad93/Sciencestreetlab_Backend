<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SetLocaleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_supported_language_header_sets_locale(): void
    {
        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/products')
            ->assertOk();

        $this->assertSame('en', app()->getLocale());
    }

    public function test_region_tagged_language_header_uses_primary_tag(): void
    {
        $this->withHeader('Accept-Language', 'en-US,en;q=0.9')
            ->getJson('/api/v1/products')
            ->assertOk();

        $this->assertSame('en', app()->getLocale());
    }

    public function test_missing_or_invalid_language_header_falls_back_to_default(): void
    {
        $default = (string) config('app.locale', 'ar');

        $this->getJson('/api/v1/products')->assertOk();
        $this->assertSame($default, app()->getLocale());

        $this->withHeader('Accept-Language', 'fr-FR')
            ->getJson('/api/v1/products')
            ->assertOk();
        $this->assertSame($default, app()->getLocale());
    }
}
