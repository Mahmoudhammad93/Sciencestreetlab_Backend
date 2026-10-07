<?php

declare(strict_types=1);

namespace App\Modules\Observability\Infrastructure\Providers;

use App\Modules\Observability\Application\Services\ErrorIncidentRecorder;
use App\Modules\Observability\Http\Controllers\Api\ErrorProbeController;
use App\Shared\Kernel\ModuleServiceProvider;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

final class ObservabilityServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Observability';
    }

    public function boot(): void
    {
        parent::boot();

        $this->registerRuntimeHooks();
        $this->registerLocalProbes();
        $commands = [
            \App\Modules\Observability\Infrastructure\Console\PruneErrorIncidentsCommand::class,
        ];
        if ($this->app->environment(['local', 'testing'])) {
            $commands[] = \App\Modules\Observability\Infrastructure\Console\BoomCommand::class;
        }
        $this->commands($commands);
    }

    private function registerRuntimeHooks(): void
    {
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            ErrorIncidentRecorder::resetRequestBudget();
            ErrorIncidentRecorder::setJobContext([
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'attempts' => $event->job->attempts(),
            ]);
        });
        Event::listen(JobProcessed::class, function (): void {
            ErrorIncidentRecorder::clearJobContext();
        });
        Event::listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event): void {
            ErrorIncidentRecorder::setJobContext([
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'attempts' => $event->job->attempts(),
            ]);
        });

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $command = is_string($event->command) ? $event->command : null;
            if (in_array($command, ['schedule:run', 'schedule:work', 'queue:work', 'queue:listen'], true)) {
                return;
            }
            ErrorIncidentRecorder::setCommandContext($command);
        });

        Event::listen(ScheduledTaskStarting::class, function (): void {
            ErrorIncidentRecorder::setSourceHint('scheduler');
        });
        Event::listen(ScheduledTaskFinished::class, function (): void {
            ErrorIncidentRecorder::setSourceHint(null);
        });
        Event::listen(ScheduledTaskFailed::class, function (): void {
            ErrorIncidentRecorder::setSourceHint('scheduler');
        });
    }

    private function registerLocalProbes(): void
    {
        if (! $this->app->environment(['local', 'testing'])) {
            return;
        }

        Route::middleware('api')->prefix('api/v1')->group(function (): void {
            Route::get('/__observability/boom', [ErrorProbeController::class, 'boom']);
            Route::middleware('auth:sanctum')->get('/__observability/boom-auth', [ErrorProbeController::class, 'boomAuth']);
        });
    }
}
