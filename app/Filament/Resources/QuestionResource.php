<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\QuestionResource\Pages;
use App\Modules\Assessment\Application\Services\InteractiveQuestionStorageService;
use App\Modules\Assessment\Application\Services\QuestionDuplicationService;
use App\Modules\Assessment\Domain\Enums\QuestionDifficulty;
use App\Modules\Assessment\Domain\Enums\QuestionStatus;
use App\Modules\Assessment\Domain\Enums\QuestionType;
use App\Modules\Assessment\Infrastructure\Persistence\Models\Question;
use App\Modules\Assessment\Infrastructure\Persistence\Models\QuestionBank;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;

class QuestionResource extends Resource
{
    protected static ?string $model = Question::class;

    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationGroup = 'Assessment';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('question_bank_id')
                ->label('Question Bank')
                ->options(fn () => QuestionBank::query()->get()->mapWithKeys(
                    fn (QuestionBank $b) => [$b->id => $b->getTranslation('title', app()->getLocale())]
                ))
                ->searchable()
                ->nullable(),
            Forms\Components\Select::make('quiz_id')
                ->relationship('quiz', 'id')
                ->label('Fixed Quiz (optional)')
                ->nullable()
                ->helperText('Leave empty for bank-only reusable questions.'),
            Forms\Components\Select::make('question_type')
                ->options(collect(QuestionType::assessmentCases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                ->required()
                ->live(),
            Forms\Components\Select::make('difficulty')
                ->options(collect(QuestionDifficulty::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                ->required()
                ->default(QuestionDifficulty::Medium->value),
            Forms\Components\Select::make('status')
                ->options(collect(QuestionStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                ->required()
                ->default(QuestionStatus::Published->value),
            Forms\Components\TextInput::make('points')->numeric()->default(1)->required(),
            Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
            Forms\Components\Select::make('tags')
                ->relationship('tags', 'slug')
                ->multiple()
                ->preload()
                ->createOptionForm([
                    Forms\Components\TextInput::make('slug')->required(),
                    Forms\Components\TextInput::make('name.ar')->label('Name AR')->required(),
                    Forms\Components\TextInput::make('name.en')->label('Name EN'),
                ])
                ->helperText('Optional tags for filtering and generated quizzes'),
            Forms\Components\Textarea::make('body.ar')->label('Body (AR)')->required()->columnSpanFull(),
            Forms\Components\Textarea::make('body.en')->label('Body (EN)')->columnSpanFull(),
            Forms\Components\Textarea::make('explanation.ar')->label('Explanation (AR)')->columnSpanFull(),
            Forms\Components\Textarea::make('explanation.en')->label('Explanation (EN)')->columnSpanFull(),

            SpatieMediaLibraryFileUpload::make('question_image')
                ->label('Question image')
                ->collection('question_image')
                ->image()
                ->imagePreviewHeight('200')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                ->maxSize(5120)
                ->helperText('Optional prompt image (JPEG/PNG/WebP/GIF, max 5MB). Used for written/long-answer questions.')
                ->visible(fn (Get $get) => in_array($get('question_type'), [
                    QuestionType::LongAnswer->value,
                    QuestionType::ShortAnswer->value,
                    QuestionType::DragDrop->value,
                ], true))
                ->columnSpanFull(),

            Forms\Components\Repeater::make('options')
                ->relationship()
                ->schema([
                    Forms\Components\TextInput::make('label.ar')->label('Label AR')->required(),
                    Forms\Components\TextInput::make('label.en')->label('Label EN'),
                    Forms\Components\Toggle::make('is_correct')->default(false),
                    Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
                    Forms\Components\KeyValue::make('meta')->nullable(),
                ])
                ->visible(fn (Get $get) => in_array($get('question_type'), [
                    QuestionType::SingleChoice->value,
                    QuestionType::MultipleChoice->value,
                    QuestionType::TrueFalse->value,
                    QuestionType::Matching->value,
                    QuestionType::Ordering->value,
                ], true))
                ->columnSpanFull(),

            Forms\Components\Section::make('Drag & drop configuration')
                ->visible(fn (Get $get) => $get('question_type') === QuestionType::DragDrop->value)
                ->schema([
                    Forms\Components\Repeater::make('drag_items')
                        ->label('Draggable items')
                        ->schema([
                            Forms\Components\TextInput::make('key')->required()->helperText('Stable unique key, e.g. item_1'),
                            Forms\Components\TextInput::make('label_ar')->label('Label AR')->required(),
                            Forms\Components\TextInput::make('label_en')->label('Label EN'),
                        ])
                        ->defaultItems(2)
                        ->columnSpanFull(),
                    Forms\Components\Repeater::make('drag_zones')
                        ->label('Drop zones')
                        ->schema([
                            Forms\Components\TextInput::make('key')->required()->helperText('Stable unique key, e.g. zone_1'),
                            Forms\Components\TextInput::make('label_ar')->label('Label AR')->required(),
                            Forms\Components\TextInput::make('label_en')->label('Label EN'),
                        ])
                        ->defaultItems(2)
                        ->columnSpanFull(),
                    Forms\Components\Repeater::make('drag_mappings')
                        ->label('Correct mappings (item → zone)')
                        ->schema([
                            Forms\Components\TextInput::make('item_key')->required(),
                            Forms\Components\TextInput::make('zone_key')->required(),
                        ])
                        ->helperText('Never exposed to students. Keys must exist in items/zones.')
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),

            Forms\Components\KeyValue::make('answer_key')
                ->label('Answer key')
                ->helperText('short/fill: accepted JSON list via key "accepted". numeric: value + tolerance. interactive: expected payload.')
                ->visible(fn (Get $get) => in_array($get('question_type'), [
                    QuestionType::ShortAnswer->value,
                    QuestionType::FillBlank->value,
                    QuestionType::Numeric->value,
                    QuestionType::InteractiveHtml->value,
                ], true))
                ->columnSpanFull(),

            Forms\Components\TextInput::make('interactive_type')
                ->visible(fn (Get $get) => $get('question_type') === QuestionType::InteractiveHtml->value)
                ->helperText('e.g. drag_drop, hotspot, custom'),
            Forms\Components\KeyValue::make('interactive_config')
                ->visible(fn (Get $get) => $get('question_type') === QuestionType::InteractiveHtml->value),
            Forms\Components\FileUpload::make('interactive_html_upload')
                ->label('Activity HTML')
                ->acceptedFileTypes(['text/html', 'application/xhtml+xml', 'application/octet-stream'])
                ->maxSize(51200)
                ->previewable(false)
                ->disk('local')
                ->directory('tmp/interactive-uploads')
                ->visible(fn (Get $get) => $get('question_type') === QuestionType::InteractiveHtml->value)
                ->dehydrated(false),
            Forms\Components\Select::make('interactive_activity_id')
                ->label('Linked Interactive Activity')
                ->relationship('interactiveActivity', 'id')
                ->searchable()
                ->nullable()
                ->visible(fn (Get $get) => in_array($get('question_type'), [
                    QuestionType::InteractiveHtml->value,
                    QuestionType::InteractiveActivity->value,
                ], true))
                ->helperText('Prefer first-class Interactive Activities for full HTML packages.'),
            Forms\Components\TextInput::make('interactive_path')
                ->disabled()
                ->dehydrated(false)
                ->visible(fn (Get $get) => $get('question_type') === QuestionType::InteractiveHtml->value),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('body')->limit(40)->searchable(),
                Tables\Columns\TextColumn::make('question_type')->badge(),
                Tables\Columns\TextColumn::make('difficulty')->badge(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('bank.title')->label('Bank'),
                Tables\Columns\TextColumn::make('points'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('question_type')
                    ->options(collect(QuestionType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name])),
                Tables\Filters\SelectFilter::make('difficulty')
                    ->options(collect(QuestionDifficulty::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name])),
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(QuestionStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name])),
                Tables\Filters\SelectFilter::make('question_bank_id')->relationship('bank', 'id'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('duplicate')
                    ->icon('heroicon-o-document-duplicate')
                    ->action(function (Question $record): void {
                        $copy = app(QuestionDuplicationService::class)->duplicate($record);
                        Notification::make()->title('Question duplicated #'.$copy->id)->success()->send();
                    }),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuestions::route('/'),
            'create' => Pages\CreateQuestion::route('/create'),
            'edit' => Pages\EditQuestion::route('/{record}/edit'),
        ];
    }

    /**
     * Expand answer_key into Filament drag_drop builder fields.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function expandDragDropFormData(array $data): array
    {
        $key = is_array($data['answer_key'] ?? null) ? $data['answer_key'] : [];
        $data['drag_items'] = collect($key['items'] ?? [])->map(function ($row) {
            $label = is_array($row['label'] ?? null) ? $row['label'] : ['ar' => (string) ($row['label'] ?? ''), 'en' => ''];

            return [
                'key' => $row['key'] ?? '',
                'label_ar' => $label['ar'] ?? '',
                'label_en' => $label['en'] ?? '',
            ];
        })->all();
        $data['drag_zones'] = collect($key['zones'] ?? $key['drop_zones'] ?? [])->map(function ($row) {
            $label = is_array($row['label'] ?? null) ? $row['label'] : ['ar' => (string) ($row['label'] ?? ''), 'en' => ''];

            return [
                'key' => $row['key'] ?? '',
                'label_ar' => $label['ar'] ?? '',
                'label_en' => $label['en'] ?? '',
            ];
        })->all();
        $mappings = $key['correct_mappings'] ?? $key['answer_key'] ?? [];
        $data['drag_mappings'] = collect(is_array($mappings) ? $mappings : [])->map(
            fn ($zone, $item) => ['item_key' => (string) $item, 'zone_key' => (string) $zone]
        )->values()->all();

        return $data;
    }

    /**
     * Collapse Filament drag_drop builder fields into answer_key. Strips builder fields.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function collapseDragDropFormData(array $data): array
    {
        if (($data['question_type'] ?? null) !== QuestionType::DragDrop->value) {
            unset($data['drag_items'], $data['drag_zones'], $data['drag_mappings']);

            return $data;
        }

        $items = [];
        $itemKeys = [];
        foreach ($data['drag_items'] ?? [] as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '' || isset($itemKeys[$key])) {
                continue;
            }
            $itemKeys[$key] = true;
            $items[] = [
                'key' => $key,
                'label' => [
                    'ar' => (string) ($row['label_ar'] ?? ''),
                    'en' => (string) ($row['label_en'] ?? ''),
                ],
            ];
        }

        $zones = [];
        $zoneKeys = [];
        foreach ($data['drag_zones'] ?? [] as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '' || isset($zoneKeys[$key])) {
                continue;
            }
            $zoneKeys[$key] = true;
            $zones[] = [
                'key' => $key,
                'label' => [
                    'ar' => (string) ($row['label_ar'] ?? ''),
                    'en' => (string) ($row['label_en'] ?? ''),
                ],
            ];
        }

        $correct = [];
        foreach ($data['drag_mappings'] ?? [] as $row) {
            $item = (string) ($row['item_key'] ?? '');
            $zone = (string) ($row['zone_key'] ?? '');
            if ($item === '' || $zone === '') {
                continue;
            }
            if (! isset($itemKeys[$item]) || ! isset($zoneKeys[$zone])) {
                continue;
            }
            $correct[$item] = $zone;
        }

        $data['answer_key'] = [
            'items' => $items,
            'zones' => $zones,
            'correct_mappings' => $correct,
        ];
        unset($data['drag_items'], $data['drag_zones'], $data['drag_mappings']);

        return $data;
    }
}
