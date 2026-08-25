<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\CourseResource;
use App\Modules\Learning\Infrastructure\Persistence\Models\Enrollment;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestEnrollmentsWidget extends BaseWidget
{
    protected static ?int $sort = 5;

    protected int | string | array $columnSpan = 1;

    protected static ?string $heading = 'Latest enrollments';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Enrollment::query()
                    ->with(['user', 'course'])
                    ->latest('enrolled_at')
            )
            ->defaultPaginationPageOption(5)
            ->paginated([5])
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Student')
                    ->searchable(),
                Tables\Columns\TextColumn::make('course.title')
                    ->label('Course')
                    ->limit(28)
                    ->url(fn (Enrollment $record): ?string => $record->course
                        ? CourseResource::getUrl('edit', ['record' => $record->course])
                        : null),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => is_object($state) ? $state->name : (string) $state),
                Tables\Columns\TextColumn::make('progress_percent')
                    ->label('Progress')
                    ->suffix('%')
                    ->sortable(),
                Tables\Columns\TextColumn::make('enrolled_at')
                    ->since()
                    ->sortable(),
            ]);
    }
}
