<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Certification\Application\Services\CertificatePdfGenerator;
use App\Modules\Certification\Infrastructure\Persistence\Models\Certificate;
use Illuminate\Console\Command;

final class RefreshStaleCertificateRendersCommand extends Command
{
    protected $signature = 'certificates:refresh-stale-renders {--id= : Refresh a single certificate id}';

    protected $description = 'Rebuild snapshot + PDF for certificates issued by the legacy generic renderer.';

    public function handle(CertificatePdfGenerator $pdfs): int
    {
        $query = Certificate::query()->with(['user', 'course', 'template']);
        if ($this->option('id')) {
            $query->where('id', (int) $this->option('id'));
        }

        $count = 0;
        $query->orderBy('id')->each(function (Certificate $certificate) use ($pdfs, &$count): void {
            $before = is_array($certificate->metadata) ? $certificate->metadata : [];
            $path = $pdfs->ensureGenerated($certificate);
            $certificate->refresh();
            $after = is_array($certificate->metadata) ? $certificate->metadata : [];
            if (($before['render_fingerprint'] ?? null) !== ($after['render_fingerprint'] ?? null)
                || ($before['renderer'] ?? null) !== ($after['renderer'] ?? null)
                || $path !== $certificate->pdf_path) {
                $count++;
                $this->info("Refreshed certificate {$certificate->id} ({$certificate->certificate_number})");
            }
        });

        $this->info("Done. Refreshed {$count} certificate(s).");

        return self::SUCCESS;
    }
}
