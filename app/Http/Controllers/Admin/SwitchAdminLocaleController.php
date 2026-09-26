<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Support\AdminLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SwitchAdminLocaleController
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        AdminLocale::persist($locale, $request->user());

        return redirect()->back();
    }
}
