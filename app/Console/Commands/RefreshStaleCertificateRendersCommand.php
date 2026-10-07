<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Certification\Application\Services\CertificatePdfGenerator;
use App\Modules\Certification\Application\Services\CertificateTemplateRenderer;
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
            $meta = is_array($certificate->metadata) ? $certificate->metadata : [];
            $fresh = ($meta['renderer'] ?? null) === CertificateTemplateRenderer::RENDERER_VERSION
                && is_array($meta['layout_snapshot'] ?? null);

            if ($fresh && $certificate->pdf_path) {
                return;
            }

            $pdfs->ensureGenerated($certificate);
            $count++;
            $this->info("Refreshed certificate {$certificate->id} ({$certificate->certificate_number})");
        });

        $this->info("Done. Refreshed {$count} certificate(s).");

        return self::SUCCESS;
    }
}
