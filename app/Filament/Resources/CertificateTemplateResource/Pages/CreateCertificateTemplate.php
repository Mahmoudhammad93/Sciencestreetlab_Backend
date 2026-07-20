<?php

declare(strict_types=1);

namespace App\Filament\Resources\CertificateTemplateResource\Pages;

use App\Filament\Resources\CertificateTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCertificateTemplate extends CreateRecord
{
    protected static string $resource = CertificateTemplateResource::class;
}
