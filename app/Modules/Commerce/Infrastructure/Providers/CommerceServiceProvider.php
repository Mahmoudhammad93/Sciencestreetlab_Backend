<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Providers;

use App\Modules\Commerce\Application\Services\CartService;
use App\Modules\Commerce\Application\Services\CheckoutService;
use App\Modules\Commerce\Application\Services\CouponService;
use App\Modules\Commerce\Application\Services\PaymentCompletionService;
use App\Modules\Commerce\Http\Support\ResolvesCart;
use App\Modules\Commerce\Infrastructure\Payment\FawaterakClient;
use App\Modules\Commerce\Infrastructure\Payment\FawaterakGateway;
use App\Modules\Commerce\Infrastructure\Payment\MyFatoorahClient;
use App\Modules\Commerce\Infrastructure\Payment\MyFatoorahGateway;
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
        $this->app->singleton(PaymentCompletionService::class);
        $this->app->singleton(PaymobClient::class);
        $this->app->singleton(PaymobHmacValidator::class);
        $this->app->singleton(FawaterakClient::class);
        $this->app->singleton(MyFatoorahClient::class);
        $this->app->singleton(ResolvesCart::class);

        $this->app->bind(PaymentGatewayInterface::class, function ($app): PaymentGatewayInterface {
            $driver = (string) config('commerce.payment_gateway', 'fawaterak');

            return match ($driver) {
                'paymob' => $app->make(PaymobGateway::class),
                'myfatoorah' => $app->make(MyFatoorahGateway::class),
                default => $app->make(FawaterakGateway::class),
            };
        });
    }
}