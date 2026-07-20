<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Providers;

use App\Modules\Assessment\Application\Services\QuizAttemptService;
use App\Modules\Assessment\Infrastructure\Grading\MultipleChoiceGrader;
use App\Modules\Assessment\Infrastructure\Grading\QuestionGraderRegistry;
use App\Modules\Assessment\Infrastructure\Grading\ShortAnswerGrader;
use App\Modules\Assessment\Infrastructure\Grading\SingleChoiceGrader;
use App\Shared\Kernel\ModuleServiceProvider;

final class AssessmentServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Assessment';
    }

    public function register(): void
    {
        $this->app->singleton(QuestionGraderRegistry::class, function () {
            return new QuestionGraderRegistry([
                new SingleChoiceGrader,
                new MultipleChoiceGrader,
                new ShortAnswerGrader,
            ]);
        });

        $this->app->singleton(QuizAttemptService::class);
    }
}
