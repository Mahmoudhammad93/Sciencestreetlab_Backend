<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\CourseResource;
use App\Modules\Learning\Domain\Enums\EnrollmentStatus;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class TopCoursesWidget extends BaseWidget
{
    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 1;

    public function getHeading(): ?string
    {
        return __('admin.widgets.top_courses.heading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Course::query()
                    ->withCount([
                        'enrollments as active_enrollments_count' => fn (Builder $q) => $q
                            ->where('status', EnrollmentStatus::Active),
                        'enrollments',
                    ])
                    ->orderByDesc('enrollments_count')
                    ->limit(8)
            )
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label(__('admin.widgets.top_courses.table.course'))
                    ->limit(36)
                    ->url(fn (Course $record): string => CourseResource::getUrl('edit', ['record' => $record])),
                Tables\Columns\TextColumn::make('access_type')
                    ->label(__('admin.widgets.top_courses.table.type'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => is_object($state) ? $state->value : (string) $state),
                Tables\Columns\TextColumn::make('active_enrollments_count')
                    ->label(__('admin.widgets.top_courses.table.active'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('enrollments_count')
                    ->label(__('admin.widgets.top_courses.table.total'))
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_published')
                    ->label(__('admin.widgets.top_courses.table.live'))
                    ->boolean(),
            ]);
    }
}
