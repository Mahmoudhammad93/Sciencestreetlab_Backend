<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

interface PaymentGatewayInterface
{
    /**
     * @param  object  $order  Commerce Order model
     */
    public function initiate(object $order): PaymentInitiationResult;

  /**
     * @return object Commerce Payment model
     */
    public function handleCallback(array $payload): object;

    /**
     * @param  object  $payment  Commerce Payment model
     */
    public function refund(object $payment, float $amount): RefundResult;
}
