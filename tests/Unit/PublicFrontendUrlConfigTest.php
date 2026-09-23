<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

final class PublicFrontendUrlConfigTest extends TestCase
{
    public function test_ip_frontend_url_is_replaced_when_app_url_is_a_domain(): void
    {
        config([
            'app.url' => 'https://app.sciencestreetlab.com',
            'sciencestreet.frontend_url' => 'http://13.39.47.202',
        ]);

        $provider = new \App\Providers\AppServiceProvider($this->app);
        $method = new \ReflectionMethod($provider, 'configurePublicFrontendUrl');
        $method->setAccessible(true);
        $method->invoke($provider);

        $this->assertSame(
            'https://app.sciencestreetlab.com',
            config('sciencestreet.frontend_url'),
        );
    }

    public function test_ip_app_url_is_replaced_when_frontend_url_is_a_domain(): void
    {
        config([
            'app.url' => 'http://13.39.47.202',
            'sciencestreet.frontend_url' => 'https://app.sciencestreetlab.com',
            'filesystems.disks.public.url' => 'http://13.39.47.202/storage',
        ]);

        $provider = new \App\Providers\AppServiceProvider($this->app);
        $method = new \ReflectionMethod($provider, 'configurePublicFrontendUrl');
        $method->setAccessible(true);
        $method->invoke($provider);

        $this->assertSame('https://app.sciencestreetlab.com', config('app.url'));
        $this->assertSame(
            'https://app.sciencestreetlab.com/storage',
            config('filesystems.disks.public.url'),
        );
    }
}
