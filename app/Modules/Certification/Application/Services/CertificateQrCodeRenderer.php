<?php

declare(strict_types=1);

namespace App\Modules\Certification\Application\Services;

use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

final class CertificateQrCodeRenderer
{
    public function dataUri(string $url): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'outputBase64' => true,
            'scale' => 5,
            'addQuietzone' => true,
        ]);

        $png = (new QRCode($options))->render($url);

        if (! is_string($png) || $png === '') {
            throw new \RuntimeException('Certificate verification QR could not be generated.');
        }

        if (str_starts_with($png, 'data:')) {
            return $png;
        }

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
