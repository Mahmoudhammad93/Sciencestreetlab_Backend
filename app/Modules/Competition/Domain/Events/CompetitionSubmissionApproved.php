<?php

declare(strict_types=1);

namespace App\Modules\Competition\Domain\Events;

use App\Modules\Competition\Infrastructure\Persistence\Models\CompetitionSubmission;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class CompetitionSubmissionApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly CompetitionSubmission $submission) {}
}
