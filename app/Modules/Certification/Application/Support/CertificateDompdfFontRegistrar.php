<?php

declare(strict_types=1);

namespace App\Modules\Certification\Application\Support;

use Dompdf\Dompdf;
use Illuminate\Support\Facades\File;

/**
 * Registers bundled certificate fonts into DomPDF's font directory.
 */
final class CertificateDompdfFontRegistrar
{
    public function register(Dompdf $dompdf): void
    {
        $fontDir = storage_path('fonts');
        File::ensureDirectoryExists($fontDir);

        $options = $dompdf->getOptions();
        $options->setFontDir($fontDir);
        $options->setFontCache($fontDir);
        $chroot = $options->getChroot();
        $chroots = is_array($chroot) ? $chroot : [$chroot];
        foreach ([base_path(), $fontDir, resource_path('fonts')] as $path) {
            if (! in_array($path, $chroots, true)) {
                $chroots[] = $path;
            }
        }
        $options->setChroot($chroots);

        $metrics = $dompdf->getFontMetrics();
        foreach (CertificateFontRegistry::localFontFiles() as $family => $absolutePath) {
            if (! is_file($absolutePath)) {
                continue;
            }

            // DomPDF validates local URIs against chroot; use file:// URI.
            $uri = 'file://'.$absolutePath;
            $metrics->registerFont(
                [
                    'family' => $family,
                    'style' => 'normal',
                    'weight' => 'normal',
                ],
                $uri
            );

            if ($family === 'Cairo ExtraBold') {
                $metrics->registerFont(
                    [
                        'family' => CertificateFontRegistry::FAMILY_CAIRO,
                        'style' => 'normal',
                        'weight' => 'bold',
                    ],
                    $uri
                );
            }
        }
    }
}
