<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Providers;

use App\Modules\Commerce\Application\Services\CartService;
use App\Modules\Commerce\Application\Services\CheckoutService;
use App\Modules\Commerce\Application\Services\CouponService;
use App\Modules\Commerce\Infrastructure\Payment\PaymobClient;
use App\Modules\Commerce\Infrastructure\Payment\PaymobGateway;
use App\Modules\Commerce\Infrastructure\Payment\PaymobHmacValidator;
use App\Shared\Contracts\PaymentGatewayInterface;
use App\Shared\Kernel\ModuleServiceProvider;

final class CommerceServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Commerce';
    }

    public function register(): void
    {
        $this->app->singleton(CartService::class);
        $this->app->singleton(CouponService::class);
        $this->app->singleton(CheckoutService::class);
        $this->app->singleton(PaymobClient::class);
        $this->app->singleton(PaymobHmacValidator::class);
        $this->app->singleton(\App\Modules\Commerce\Http\Support\ResolvesCart::class);
        $this->app->bind(PaymentGatewayInterface::class, PaymobGateway::class);
    }
}
