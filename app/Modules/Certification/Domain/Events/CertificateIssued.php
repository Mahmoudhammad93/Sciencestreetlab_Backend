<?php

declare(strict_types=1);

namespace App\Modules\Certification\Domain\Events;

use App\Modules\Certification\Infrastructure\Persistence\Models\Certificate;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class CertificateIssued
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Certificate $certificate) {}
}
