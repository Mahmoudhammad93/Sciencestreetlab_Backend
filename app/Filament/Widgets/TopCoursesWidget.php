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

    protected static ?string $heading = 'Top courses by enrollment';

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
                    ->label('Course')
                    ->limit(36)
                    ->url(fn (Course $record): string => CourseResource::getUrl('edit', ['record' => $record])),
                Tables\Columns\TextColumn::make('access_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn ($state) => is_object($state) ? $state->value : (string) $state),
                Tables\Columns\TextColumn::make('active_enrollments_count')
                    ->label('Active')
                    ->sortable(),
                Tables\Columns\TextColumn::make('enrollments_count')
                    ->label('Total')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_published')
                    ->label('Live')
                    ->boolean(),
            ]);
    }
}
