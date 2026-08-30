<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Commerce\Infrastructure\Payment\MyFatoorahPhoneNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MyFatoorahPhoneNormalizerTest extends TestCase
{
    #[DataProvider('phoneProvider')]
    public function test_normalizes_phone_for_myfatoorah(string $input, string $expected): void
    {
        $this->assertSame($expected, MyFatoorahPhoneNormalizer::normalize($input));
        $this->assertLessThanOrEqual(11, strlen(MyFatoorahPhoneNormalizer::normalize($input)));
    }

    public static function phoneProvider(): array
    {
        return [
            ['01012345678', '01012345678'],
            ['+20 101 234 5678', '01012345678'],
            ['201012345678', '01012345678'],
            ['1012345678', '01012345678'],
            ['01000000000', '01000000000'],
        ];
    }
}
