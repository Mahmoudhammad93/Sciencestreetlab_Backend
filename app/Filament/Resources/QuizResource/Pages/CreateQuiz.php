<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuizResource\Pages;

use App\Filament\Resources\QuizResource;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use Filament\Resources\Pages\CreateRecord;

final class CreateQuiz extends CreateRecord
{
    protected static string $resource = QuizResource::class;

    public function mount(): void
    {
        parent::mount();

        $lessonId = request()->integer('lesson_id');
        if ($lessonId <= 0 || ! Lesson::query()->whereKey($lessonId)->exists()) {
            return;
        }

        $this->form->fill([
            'quizable_type' => Lesson::class,
            'quizable_id' => $lessonId,
        ]);
    }
}
