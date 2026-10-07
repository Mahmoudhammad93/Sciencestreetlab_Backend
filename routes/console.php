<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Bosta webhook is primary. Reconciliation is a safety net for missed webhooks.
| Production runs schedule:work in science-street-scheduler.
*/
Schedule::command('bosta:reconcile-shipments')
    ->everyFifteenMinutes()
    ->withoutOverlapping(20)
    ->when(fn (): bool => (bool) config('bosta.enabled'));

Schedule::command('observability:prune-error-incidents')
    ->dailyAt('03:40')
    ->withoutOverlapping();
