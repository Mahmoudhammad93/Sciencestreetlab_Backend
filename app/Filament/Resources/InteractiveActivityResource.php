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

    protected static ?string $navigationGroup = 'Assessment';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('lesson_id')
                ->label('Lesson')
                ->options(fn () => Lesson::query()->orderBy('id')->get()->mapWithKeys(
                    fn (Lesson $l) => [$l->id => $l->slug.' (#'.$l->id.')']
                ))
                ->searchable()
                ->required(),
            Forms\Components\TextInput::make('title.ar')->label('Title (AR)')->required(),
            Forms\Components\TextInput::make('title.en')->label('Title (EN)'),
            Forms\Components\Textarea::make('description.ar')->label('Description (AR)')->columnSpanFull(),
            Forms\Components\Textarea::make('description.en')->label('Description (EN)')->columnSpanFull(),
            Forms\Components\Textarea::make('instructions.ar')->label('Instructions (AR)')->columnSpanFull(),
            Forms\Components\Textarea::make('instructions.en')->label('Instructions (EN)')->columnSpanFull(),
            Forms\Components\Select::make('activity_type')
                ->options(collect(InteractiveActivityType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                ->required()
                ->default(InteractiveActivityType::Custom->value),
            Forms\Components\Select::make('difficulty')
                ->options(collect(QuestionDifficulty::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                ->required()
                ->default(QuestionDifficulty::Medium->value),
            Forms\Components\Select::make('status')
                ->options(collect(InteractiveActivityStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                ->required()
                ->default(InteractiveActivityStatus::Draft->value),
            Forms\Components\TextInput::make('points')->numeric()->default(10)->required(),
            Forms\Components\TextInput::make('estimated_time_seconds')->numeric()->nullable(),
            Forms\Components\TextInput::make('entry_file')->default('index.html'),
            Forms\Components\KeyValue::make('activity_config')
                ->helperText('Optional expected answers map under key "expected" for server verification.')
                ->columnSpanFull(),
            Forms\Components\FileUpload::make('package_zip')
                ->label('Activity package (ZIP)')
                ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])
                ->disk('local')
                ->directory('tmp/interactive-activity-uploads')
                ->dehydrated(false)
                ->helperText('ZIP containing index.html + css/js/images/audio. Extracted to an isolated versioned folder.'),
            Forms\Components\FileUpload::make('package_html')
                ->label('Single HTML activity file')
                ->acceptedFileTypes(['text/html', 'application/xhtml+xml'])
                ->disk('local')
                ->directory('tmp/interactive-activity-uploads')
                ->dehydrated(false)
                ->helperText('Upload one complete standalone HTML activity (stored as index.html). The platform does not parse game logic.'),
            Forms\Components\TextInput::make('activity_package_path')->disabled()->dehydrated(false),
            Forms\Components\TextInput::make('version')->disabled()->dehydrated(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('title')->limit(40)->searchable(),
                Tables\Columns\TextColumn::make('activity_type')->badge(),
                Tables\Columns\TextColumn::make('difficulty')->badge(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('version'),
                Tables\Columns\TextColumn::make('lesson.slug')->label('Lesson'),
                Tables\Columns\TextColumn::make('points'),
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
                        Notification::make()->title('Activity duplicated #'.$copy->id)->success()->send();
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

        $upload = new UploadedFile($absolute, basename($absolute), null, null, true);
        app(InteractiveActivityPackageService::class)->storeUploadedPackage(
            $record,
            $upload,
            $record->entry_file ?: 'index.html',
        );
        Storage::disk('local')->delete($path);
    }
}
