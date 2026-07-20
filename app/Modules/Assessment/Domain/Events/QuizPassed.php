<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class QuizPassed
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly object $attempt) {}
}
