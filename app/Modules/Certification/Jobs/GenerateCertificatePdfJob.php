<?php

declare(strict_types=1);

namespace App\Modules\Certification\Jobs;

use App\Modules\Certification\Application\Services\CertificatePdfGenerator;
use App\Modules\Certification\Infrastructure\Persistence\Models\Certificate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class GenerateCertificatePdfJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Certificate $certificate) {}

    public function handle(CertificatePdfGenerator $generator): void
    {
        $path = $generator->generate($this->certificate);

        $this->certificate->update(['pdf_path' => $path]);
    }
}
