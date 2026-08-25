<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\SiteSettings;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public function getHeading(): string
    {
        return SiteSettings::getString('dashboard_heading', 'Science Street Lab');
    }

    public function getSubheading(): ?string
    {
        $subheading = SiteSettings::getString('dashboard_subheading');

        return $subheading !== '' ? $subheading : null;
    }
}
