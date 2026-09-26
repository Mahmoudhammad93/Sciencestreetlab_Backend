<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\InteractiveActivityResource\Pages;
use App\Modules\Assessment\Application\Services\InteractiveActivityPackageService;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityStatus;
use App\Modules\Assessment\Domain\Enums\InteractiveActivityType;
use App\Modules\Assessment\Domain\Enums\QuestionDifficulty;
use App\Modules\Assessment\Infrastructure\Persistence\Models\InteractiveActivity;
use App\Modules\Learning\Infrastructure\Persistence\Models\Lesson;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class InteractiveActivityResource extends Resource
{
    protected static ?string $model = InteractiveActivity::class;

    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.groups.assessment');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.nav.interactive_activities');
    }

    public static function getModelLabel(): string
    {
        return __('admin.nav.interactive_activities');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.nav.interactive_activities');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('lesson_id')
                ->label(__('admin.interactive_activities.fields.lesson'))
                ->options(fn () => Lesson::query()->orderBy('id')->get()->mapWithKeys(
                    fn (Lesson $l) => [$l->id => $l->slug.' (#'.$l->id.')']
                ))
                ->searchable()
                ->required(),
            Forms\Components\Select::make('topic_id')
                ->label(__('admin.interactive_activities.fields.topic'))
                ->relationship('topic', 'slug')
                ->searchable()
                ->preload()
                ->helperText(__('admin.interactive_activities.fields.topic_help')),
            Forms\Components\TextInput::make('title.ar')->label(__('admin.interactive_activities.fields.title_ar'))->required(),
            Forms\Components\TextInput::make('title.en')->label(__('admin.interactive_activities.fields.title_en')),
            Forms\Components\Textarea::make('description.ar')->label(__('admin.interactive_activities.fields.description_ar'))->columnSpanFull(),
            Forms\Components\Textarea::make('description.en')->label(__('admin.interactive_activities.fields.description_en'))->columnSpanFull(),
            Forms\Components\Textarea::make('instructions.ar')->label(__('admin.interactive_activities.fields.instructions_ar'))->columnSpanFull(),
            Forms\Components\Textarea::make('instructions.en')->label(__('admin.interactive_activities.fields.instructions_en'))->columnSpanFull(),
            Forms\Components\Select::make('activity_type')
                ->label(__('admin.interactive_activities.fields.activity_type'))
                ->options(collect(InteractiveActivityType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                ->required()
                ->default(InteractiveActivityType::Custom->value),
            Forms\Components\Select::make('difficulty')
                ->label(__('admin.interactive_activities.fields.difficulty'))
                ->options(collect(QuestionDifficulty::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                ->required()
                ->default(QuestionDifficulty::Medium->value),
            Forms\Components\Select::make('status')
                ->label(__('admin.interactive_activities.fields.status'))
                ->options(collect(InteractiveActivityStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                ->required()
                ->default(InteractiveActivityStatus::Draft->value),
            Forms\Components\TextInput::make('points')->label(__('admin.interactive_activities.fields.points'))->numeric()->default(10)->required(),
            Forms\Components\TextInput::make('estimated_time_seconds')->label(__('admin.interactive_activities.fields.estimated_time_seconds'))->numeric()->nullable(),
            Forms\Components\TextInput::make('entry_file')->label(__('admin.interactive_activities.fields.entry_file'))->default('index.html')->extraInputAttributes(['dir' => 'ltr']),
            Forms\Components\KeyValue::make('activity_config')
                ->helperText(__('admin.interactive_activities.fields.activity_config_help'))
                ->extraInputAttributes(['dir' => 'ltr'])
                ->columnSpanFull(),
            Forms\Components\FileUpload::make('package_zip')
                ->label(__('admin.interactive_activities.fields.upload_zip'))
                ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed', 'application/octet-stream'])
                ->maxSize(65536)
                ->previewable(false)
                ->disk('local')
                ->visibility('private')
                ->directory('tmp/interactive-activity-uploads')
                ->dehydrated(false)
                ->helperText(__('admin.interactive_activities.fields.upload_zip_help')),
            Forms\Components\FileUpload::make('package_html')
                ->label(__('admin.interactive_activities.fields.upload_html'))
                ->acceptedFileTypes(['text/html', 'application/xhtml+xml', 'application/octet-stream'])
                ->maxSize(65536)
                ->previewable(false)
                ->disk('local')
                ->visibility('private')
                ->directory('tmp/interactive-activity-uploads')
                ->dehydrated(false)
                ->helperText(__('admin.interactive_activities.fields.upload_html_help')),
            Forms\Components\TextInput::make('activity_package_path')->label(__('admin.interactive_activities.fields.package_path'))->disabled()->dehydrated(false)->extraInputAttributes(['dir' => 'ltr']),
            Forms\Components\TextInput::make('version')->label(__('admin.interactive_activities.fields.version'))->disabled()->dehydrated(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('title')->label(__('admin.common.fields.title'))->limit(40)->searchable(),
                Tables\Columns\TextColumn::make('activity_type')->label(__('admin.interactive_activities.fields.activity_type'))->badge(),
                Tables\Columns\TextColumn::make('difficulty')->label(__('admin.interactive_activities.fields.difficulty'))->badge(),
                Tables\Columns\TextColumn::make('status')->label(__('admin.common.fields.status'))->badge(),
                Tables\Columns\TextColumn::make('version')->label(__('admin.interactive_activities.fields.version')),
                Tables\Columns\TextColumn::make('lesson.slug')->label(__('admin.interactive_activities.table.lesson')),
                Tables\Columns\TextColumn::make('points')->label(__('admin.interactive_activities.fields.points')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(InteractiveActivityStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name])),
                Tables\Filters\SelectFilter::make('difficulty')
                    ->options(collect(QuestionDifficulty::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name])),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('preview')
                    ->label(__('admin.common.actions.preview'))
                    ->icon('heroicon-o-eye')
                    ->url(fn (InteractiveActivity $record) => app(InteractiveActivityPackageService::class)->signedLaunchUrl($record) ?? '#')
                    ->openUrlInNewTab()
                    ->visible(fn (InteractiveActivity $record) => filled($record->activity_package_path)),
                Tables\Actions\Action::make('duplicate')
                    ->icon('heroicon-o-document-duplicate')
                    ->action(function (InteractiveActivity $record): void {
                        $copy = $record->replicate(['uuid', 'activity_package_path']);
                        $copy->uuid = (string) \Illuminate\Support\Str::uuid();
                        $copy->title = [
                            'ar' => ($record->getTranslation('title', 'ar') ?: 'Activity').' (نسخة)',
                            'en' => ($record->getTranslation('title', 'en') ?: 'Activity').' (copy)',
                        ];
                        $copy->status = InteractiveActivityStatus::Draft;
                        $copy->version = 1;
                        $copy->activity_package_path = null;
                        $copy->save();
                        app(InteractiveActivityPackageService::class)->duplicatePackage($record, $copy);
                        Notification::make()->title(str_replace('…', (string) $copy->id, __('admin.interactive_activities.notifications.duplicated')))->success()->send();
                    }),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInteractiveActivities::route('/'),
            'create' => Pages\CreateInteractiveActivity::route('/create'),
            'edit' => Pages\EditInteractiveActivity::route('/{record}/edit'),
        ];
    }

    public static function handlePackageUpload(InteractiveActivity $record, mixed $state): void
    {
        self::handleFileUpload($record, $state, 'package_zip');
    }

    public static function handleHtmlUpload(InteractiveActivity $record, mixed $state): void
    {
        self::handleFileUpload($record, $state, 'package_html');
    }

    private static function handleFileUpload(InteractiveActivity $record, mixed $state, string $field): void
    {
        if (! $state) {
            return;
        }

        $path = is_array($state) ? (string) ($state[0] ?? '') : (string) $state;
        if ($path === '') {
            return;
        }

        $absolute = Storage::disk('local')->path($path);
        if (! is_file($absolute)) {
            return;
        }

        try {
            $upload = new UploadedFile($absolute, basename($absolute), null, null, true);
            app(InteractiveActivityPackageService::class)->storeUploadedPackage(
                $record,
                $upload,
                $record->entry_file ?: 'index.html',
            );
            Storage::disk('local')->delete($path);
        } catch (\DomainException $e) {
            Notification::make()
                ->title(__('admin.interactive_activities.notifications.upload_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
