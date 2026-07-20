<?php

declare(strict_types=1);

namespace App\Modules\Certification\Application\Services;

use App\Modules\Certification\Infrastructure\Persistence\Models\Certificate;

final class CertificateNumberGenerator
{
    public function next(): string
    {
        $year = now()->format('Y');
        $prefix = "SSL-{$year}-";

        $last = Certificate::query()
            ->where('certificate_number', 'like', "{$prefix}%")
            ->orderByDesc('certificate_number')
            ->value('certificate_number');

        $sequence = $last
            ? ((int) substr($last, strlen($prefix))) + 1
            : 1;

        return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
