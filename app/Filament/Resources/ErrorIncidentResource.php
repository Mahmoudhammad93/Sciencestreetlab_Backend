<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ErrorIncidentResource\Pages;
use App\Modules\Observability\Infrastructure\Persistence\Models\ErrorIncident;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ErrorIncidentResource extends Resource
{
    protected static ?string $model = ErrorIncident::class;

    protected static ?string $navigationIcon = 'heroicon-o-bug-ant';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'error-logs';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.groups.system');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.error_incidents.nav');
    }

    public static function getModelLabel(): string
    {
        return __('admin.error_incidents.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.error_incidents.nav');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make(__('admin.error_incidents.sections.overview'))->schema([
                Infolists\Components\TextEntry::make('uuid')->label(__('admin.error_incidents.fields.error_id'))->copyable(),
                Infolists\Components\TextEntry::make('fingerprint')->label(__('admin.error_incidents.fields.fingerprint'))->copyable(),
                Infolists\Components\TextEntry::make('level')->label(__('admin.error_incidents.fields.severity'))->badge(),
                Infolists\Components\TextEntry::make('status')
                    ->label(__('admin.error_incidents.fields.status'))
                    ->state(fn (ErrorIncident $record): string => $record->isResolved()
                        ? __('admin.error_incidents.status.resolved')
                        : __('admin.error_incidents.status.open')),
                Infolists\Components\TextEntry::make('occurrences')->label(__('admin.error_incidents.fields.occurrences')),
                Infolists\Components\TextEntry::make('first_seen_at')->label(__('admin.error_incidents.fields.first_seen'))->dateTime()->placeholder('N/A'),
                Infolists\Components\TextEntry::make('last_seen_at')->label(__('admin.error_incidents.fields.last_seen'))->dateTime()->placeholder('N/A'),
                Infolists\Components\TextEntry::make('resolved_at')
                    ->label(__('admin.error_incidents.fields.resolved_at'))
                    ->dateTime()
                    ->placeholder('N/A'),
                Infolists\Components\TextEntry::make('resolved_by_display')
                    ->label(__('admin.error_incidents.fields.resolved_by'))
                    ->state(fn (?ErrorIncident $record): string => $record?->displayResolverName() ?? 'N/A'),
            ])->columns(3),
            Infolists\Components\Section::make(__('admin.error_incidents.sections.error'))->schema([
                Infolists\Components\TextEntry::make('exception_class')
                    ->label(__('admin.error_incidents.fields.exception'))
                    ->copyable()
                    ->placeholder('N/A'),
                Infolists\Components\TextEntry::make('message')
                    ->label(__('admin.error_incidents.fields.message'))
                    ->placeholder('N/A')
                    ->columnSpanFull(),
                Infolists\Components\TextEntry::make('file_display')
                    ->label(__('admin.error_incidents.fields.file'))
                    ->state(fn (?ErrorIncident $record): string => $record?->display()->file($record->file) ?? 'N/A'),
                Infolists\Components\TextEntry::make('line')
                    ->label(__('admin.error_incidents.fields.line'))
                    ->placeholder('N/A')
                    ->formatStateUsing(fn (mixed $state): string => is_numeric($state) ? (string) $state : 'N/A'),
            ])->columns(2),
            Infolists\Components\Section::make(__('admin.error_incidents.sections.application'))->schema([
                Infolists\Components\TextEntry::make('module')
                    ->label(__('admin.error_incidents.fields.module'))
                    ->placeholder('N/A'),
                Infolists\Components\TextEntry::make('source')
                    ->label(__('admin.error_incidents.fields.source'))
                    ->placeholder('N/A'),
                Infolists\Components\TextEntry::make('application_class_display')
                    ->label(__('admin.error_incidents.fields.application_class'))
                    ->state(fn (?ErrorIncident $record): string => $record?->displayApplicationClass() ?? 'N/A'),
                Infolists\Components\TextEntry::make('application_method_display')
                    ->label(__('admin.error_incidents.fields.method'))
                    ->state(fn (?ErrorIncident $record): string => $record?->display()->text($record->application_method) ?? 'N/A'),
                Infolists\Components\TextEntry::make('model_display')
                    ->label(__('admin.error_incidents.fields.model'))
                    ->state(fn (?ErrorIncident $record): string => $record?->displayModel() ?? 'N/A'),
                Infolists\Components\TextEntry::make('route_name')
                    ->label(__('admin.error_incidents.fields.route'))
                    ->placeholder('N/A'),
                Infolists\Components\TextEntry::make('request_method')
                    ->label(__('admin.error_incidents.fields.http_method'))
                    ->placeholder('N/A'),
                Infolists\Components\TextEntry::make('request_path')
                    ->label(__('admin.error_incidents.fields.request_path'))
                    ->placeholder('N/A'),
                Infolists\Components\TextEntry::make('http_status')
                    ->label(__('admin.error_incidents.fields.http_status'))
                    ->placeholder('N/A'),
                Infolists\Components\TextEntry::make('request_id')
                    ->label(__('admin.error_incidents.fields.request_id'))
                    ->copyable()
                    ->placeholder('N/A'),
            ])->columns(2),
            Infolists\Components\Section::make(__('admin.error_incidents.sections.user'))->schema([
                Infolists\Components\TextEntry::make('user_type_display')
                    ->label(__('admin.error_incidents.fields.user_type'))
                    ->state(fn (?ErrorIncident $record): string => $record?->display()->userType($record->user_type) ?? 'N/A'),
                Infolists\Components\TextEntry::make('user_id')
                    ->label(__('admin.error_incidents.fields.user_id'))
                    ->placeholder('N/A'),
                Infolists\Components\TextEntry::make('user_name_display')
                    ->label(__('admin.error_incidents.fields.user_name'))
                    ->state(fn (?ErrorIncident $record): string => $record?->displayUserName() ?? 'N/A'),
                Infolists\Components\TextEntry::make('user_email_display')
                    ->label(__('admin.error_incidents.fields.user_email'))
                    ->state(fn (?ErrorIncident $record): string => $record?->displayUserEmail() ?? 'N/A'),
            ])->columns(2),
            Infolists\Components\Section::make(__('admin.error_incidents.sections.trace'))->schema([
                Infolists\Components\TextEntry::make('trace_display')
                    ->label(__('admin.error_incidents.fields.trace'))
                    ->state(fn (?ErrorIncident $record): string => $record?->formattedTrace() ?? 'N/A')
                    ->extraAttributes(['class' => 'font-mono text-xs whitespace-pre-wrap break-all'])
                    ->columnSpanFull(),
            ]),
            Infolists\Components\Section::make(__('admin.error_incidents.sections.context'))->schema([
                Infolists\Components\TextEntry::make('context_display')
                    ->label(__('admin.error_incidents.fields.context'))
                    ->state(fn (?ErrorIncident $record): string => $record?->formattedContext() ?? 'N/A')
                    ->extraAttributes(['class' => 'font-mono text-xs whitespace-pre-wrap break-all'])
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user']))
            ->defaultSort('last_seen_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('level')
                    ->label(__('admin.error_incidents.fields.severity'))
                    ->badge()
                    ->color(fn (mixed $state): string => match (is_string($state) ? $state : '') {
                        'critical' => 'danger',
                        'warning' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.error_incidents.fields.status'))
                    ->state(fn (ErrorIncident $record): string => $record->isResolved()
                        ? __('admin.error_incidents.status.resolved')
                        : __('admin.error_incidents.status.open'))
                    ->badge()
                    ->color(fn (ErrorIncident $record): string => $record->isResolved() ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('module')
                    ->label(__('admin.error_incidents.fields.module'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('exception_class')
                    ->label(__('admin.error_incidents.fields.exception'))
                    ->formatStateUsing(fn (mixed $state): string => is_string($state) && $state !== '' ? class_basename($state) : '')
                    ->searchable()
                    ->limit(40),
                Tables\Columns\TextColumn::make('message')
                    ->label(__('admin.error_incidents.fields.message'))
                    ->limit(60)
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('route_name')
                    ->label(__('admin.error_incidents.fields.route'))
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('user')
                    ->label(__('admin.error_incidents.fields.user'))
                    ->state(function (ErrorIncident $record): string {
                        $type = is_string($record->user_type) && $record->user_type !== '' ? $record->user_type : 'guest';
                        $name = $record->displayUserName();
                        if ($record->user_id) {
                            return $type.' #'.$record->user_id.($name !== 'N/A' ? ' '.$name : '');
                        }

                        return $type;
                    }),
                Tables\Columns\TextColumn::make('occurrences')
                    ->label(__('admin.error_incidents.fields.occurrences'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('first_seen_at')
                    ->label(__('admin.error_incidents.fields.first_seen'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label(__('admin.error_incidents.fields.last_seen'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('request_id')
                    ->label(__('admin.error_incidents.fields.request_id'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('uuid')
                    ->label(__('admin.error_incidents.fields.error_id'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('resolved')
                    ->label(__('admin.error_incidents.fields.status'))
                    ->placeholder(__('admin.error_incidents.filters.all'))
                    ->trueLabel(__('admin.error_incidents.status.resolved'))
                    ->falseLabel(__('admin.error_incidents.status.open'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('resolved_at'),
                        false: fn (Builder $query) => $query->whereNull('resolved_at'),
                    ),
                Tables\Filters\SelectFilter::make('level')
                    ->label(__('admin.error_incidents.fields.severity'))
                    ->options([
                        'error' => 'error',
                        'warning' => 'warning',
                        'critical' => 'critical',
                    ]),
                Tables\Filters\SelectFilter::make('module')
                    ->label(__('admin.error_incidents.fields.module'))
                    ->options(fn (): array => ErrorIncident::query()
                        ->whereNotNull('module')
                        ->distinct()
                        ->orderBy('module')
                        ->pluck('module', 'module')
                        ->all()),
                Tables\Filters\SelectFilter::make('user_type')
                    ->label(__('admin.error_incidents.fields.user_type'))
                    ->options([
                        'customer' => __('admin.error_incidents.user_types.customer'),
                        'admin' => __('admin.error_incidents.user_types.admin'),
                        'guest' => __('admin.error_incidents.user_types.guest'),
                        'system' => __('admin.error_incidents.user_types.system'),
                    ]),
                Tables\Filters\Filter::make('last_seen')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label(__('admin.error_incidents.filters.from')),
                        \Filament\Forms\Components\DatePicker::make('until')->label(__('admin.error_incidents.filters.until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('last_seen_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('last_seen_at', '<=', $date));
                    }),
                Tables\Filters\Filter::make('exception_class')
                    ->form([
                        \Filament\Forms\Components\TextInput::make('exception_class')
                            ->label(__('admin.error_incidents.fields.exception')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['exception_class'] ?? null;
                        if (! is_string($value) || $value === '') {
                            return $query;
                        }

                        return $query->where('exception_class', 'like', '%'.$value.'%');
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('resolve')
                    ->label(__('admin.error_incidents.actions.resolve'))
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (ErrorIncident $record): bool => ! $record->isResolved())
                    ->action(function (ErrorIncident $record): void {
                        $record->markResolved(auth()->id());
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListErrorIncidents::route('/'),
            'view' => Pages\ViewErrorIncident::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'resolver']);
    }
}
