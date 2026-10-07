<?php

declare(strict_types=1);

namespace App\Modules\Observability\Infrastructure\Console;

use Illuminate\Console\Command;
use RuntimeException;

/**
 * Local/testing probe only. Never registered when APP_ENV=production.
 */
final class BoomCommand extends Command
{
    protected $signature = 'observability:boom';

    protected $description = 'Throw a test exception for error monitoring (local/testing only).';

    public function handle(): never
    {
        throw new RuntimeException('observability command probe');
    }
}
