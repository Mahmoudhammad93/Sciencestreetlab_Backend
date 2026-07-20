<?php

declare(strict_types=1);

namespace App\Modules\Competition\Domain\Enums;

enum ParticipantStatus: string
{
    case Registered = 'registered';
    case Active = 'active';
    case Shortlisted = 'shortlisted';
    case Winner = 'winner';
    case Disqualified = 'disqualified';
}
