<?php

declare(strict_types=1);

namespace App\Modules\Learning\Domain\Events;

use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class CourseCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Enrollment $enrollment) {}
}
