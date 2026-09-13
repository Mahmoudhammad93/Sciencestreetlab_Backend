<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Services;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

final class EnrollmentQrCodeRenderer
{
    public function png(string $url): string
    {
        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_IMAGE_PNG,
            'outputBase64' => false,
            'scale' => 6,
            'addQuietzone' => true,
        ]);

        $png = (new QRCode($options))->render($url);

        if (! is_string($png) || $png === '') {
            throw new \RuntimeException('Enrollment verification QR could not be generated.');
        }

        return $png;
    }
}
