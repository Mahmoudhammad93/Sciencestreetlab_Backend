<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Commerce\Infrastructure\Payment\MyFatoorahReturnHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MyFatoorahReturnHandlerTest extends TestCase
{
    public function test_extracts_gateway_payment_id_from_query_variants(): void
    {
        $this->assertSame('abc', MyFatoorahReturnHandler::gatewayPaymentIdFromQuery(['paymentId' => 'abc']));
        $this->assertSame('abc', MyFatoorahReturnHandler::gatewayPaymentIdFromQuery(['PaymentId' => 'abc']));
    }

    public function test_detects_paid_invoice_status(): void
    {
        $this->assertTrue(MyFatoorahReturnHandler::isInvoicePaid(['InvoiceStatus' => 'Paid']));
        $this->assertFalse(MyFatoorahReturnHandler::isInvoicePaid(['InvoiceStatus' => 'Pending']));
    }

    #[DataProvider('transactionProvider')]
    public function test_finds_successful_transaction_id(array $status, ?string $gatewayPaymentId, string $expected): void
    {
        $this->assertSame($expected, MyFatoorahReturnHandler::successfulTransactionId($status, $gatewayPaymentId));
    }

    public static function transactionProvider(): array
    {
        return [
            [[
                'InvoiceTransactions' => [
                    ['TransactionStatus' => 'Failed', 'TransactionId' => 'fail-1', 'PaymentId' => 'p1'],
                    ['TransactionStatus' => 'Succss', 'TransactionId' => 'ok-1', 'PaymentId' => 'p2'],
                ],
            ], 'p2', 'ok-1'],
            [[
                'InvoiceTransactions' => [
                    ['TransactionStatus' => 'Succss', 'TransactionId' => 'ok-2', 'PaymentId' => 'p3'],
                ],
            ], null, 'ok-2'],
        ];
    }
}
