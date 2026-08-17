<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Infrastructure\Providers;

use App\Modules\Assessment\Application\Services\InteractiveActivityPackageService;
use App\Modules\Assessment\Application\Services\InteractiveActivityService;
use App\Modules\Assessment\Application\Services\InteractiveQuestionStorageService;
use App\Modules\Assessment\Application\Services\QuestionDuplicationService;
use App\Modules\Assessment\Application\Services\QuestionSelectionService;
use App\Modules\Assessment\Application\Services\QuizAttemptService;
use App\Modules\Assessment\Infrastructure\Grading\FillBlankGrader;
use App\Modules\Assessment\Infrastructure\Grading\InteractiveHtmlGrader;
use App\Modules\Assessment\Infrastructure\Grading\LongAnswerGrader;
use App\Modules\Assessment\Infrastructure\Grading\MatchingGrader;
use App\Modules\Assessment\Infrastructure\Grading\MultipleChoiceGrader;
use App\Modules\Assessment\Infrastructure\Grading\NumericGrader;
use App\Modules\Assessment\Infrastructure\Grading\OrderingGrader;
use App\Modules\Assessment\Infrastructure\Grading\QuestionGraderRegistry;
use App\Modules\Assessment\Infrastructure\Grading\ShortAnswerGrader;
use App\Modules\Assessment\Infrastructure\Grading\SingleChoiceGrader;
use App\Modules\Assessment\Infrastructure\Grading\TrueFalseGrader;
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
                new TrueFalseGrader,
                new MultipleChoiceGrader,
                new ShortAnswerGrader,
                new LongAnswerGrader,
                new FillBlankGrader,
                new MatchingGrader,
                new OrderingGrader,
                new NumericGrader,
                new InteractiveHtmlGrader,
            ]);
        });

        $this->app->singleton(QuestionSelectionService::class);
        $this->app->singleton(InteractiveQuestionStorageService::class);
        $this->app->singleton(InteractiveActivityPackageService::class);
        $this->app->singleton(InteractiveActivityService::class);
        $this->app->singleton(QuestionDuplicationService::class);
        $this->app->singleton(QuizAttemptService::class);
    }
}
