<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain\Enums;

/**
 * Extensible activity categories. Custom / unknown values may still be stored as strings
 * on the model when needed; this enum documents first-class types.
 */
enum InteractiveActivityType: string
{
    case DragDrop = 'drag_drop';
    case MatchingGame = 'matching_game';
    case MemoryGame = 'memory_game';
    case Hotspot = 'hotspot';
    case Labeling = 'labeling';
    case Sorting = 'sorting';
    case Ordering = 'ordering';
    case Simulation = 'simulation';
    case Puzzle = 'puzzle';
    case QuizGame = 'quiz_game';
    case InteractiveStory = 'interactive_story';
    case VirtualLab = 'virtual_lab';
    case Custom = 'custom';
}
