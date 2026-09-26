<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Forms\Components\ImageDropzone;
use App\Filament\Resources\CourseResource\Pages;
use App\Filament\Resources\CourseResource\RelationManagers\CoursePlansRelationManager;
use App\Filament\Resources\CourseResource\RelationManagers\LessonsRelationManager;
use App\Modules\Learning\Domain\Enums\AccessType;
use App\Modules\Learning\Infrastructure\Persistence\Models\Course;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CourseResource extends Resource
{
    protected static ?string $model = Course::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.groups.learning');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.nav.courses');
    }

    public static function getModelLabel(): string
    {
        return __('admin.nav.courses');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.nav.courses');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('admin.courses.sections.details'))->schema([
                Forms\Components\TextInput::make('slug')
                    ->label(__('admin.common.fields.slug'))
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->extraInputAttributes(['dir' => 'ltr']),
                Forms\Components\Select::make('access_type')
                    ->label(__('admin.courses.fields.access_type'))
                    ->options(collect(AccessType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name]))
                    ->required(),
                Forms\Components\Toggle::make('is_published')
                    ->label(__('admin.courses.fields.is_published')),
                Forms\Components\TextInput::make('title.ar')
                    ->label(__('admin.courses.fields.title_ar'))
                    ->required(),
                Forms\Components\TextInput::make('title.en')
                    ->label(__('admin.courses.fields.title_en')),
                Forms\Components\Textarea::make('short_description.ar')
                    ->label(__('admin.courses.fields.short_description_ar')),
                Forms\Components\Textarea::make('short_description.en')
                    ->label(__('admin.courses.fields.short_description_en')),
                Forms\Components\Textarea::make('description.ar')
                    ->label(__('admin.courses.fields.description_ar')),
                Forms\Components\Textarea::make('description.en')
                    ->label(__('admin.courses.fields.description_en')),
                Forms\Components\TextInput::make('estimated_hours')
                    ->label(__('admin.courses.fields.estimated_hours'))
                    ->numeric()
                    ->minValue(0),
            ])->columns(2),
            Forms\Components\Section::make(__('admin.courses.sections.image'))->schema([
                ImageDropzone::make(
                    'image_url',
                    'courses',
                    __('admin.courses.fields.image'),
                    __('admin.courses.fields.image_help')
                ),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image_url')
                    ->label(__('admin.courses.table.image'))
                    ->getStateUsing(fn (Course $record): ?string => ImageDropzone::publicUrl($record->image_url))
                    ->circular()
                    ->defaultImageUrl(null),
                Tables\Columns\TextColumn::make('title')
                    ->label(__('admin.common.fields.title'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('slug')
                    ->label(__('admin.common.fields.slug'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('access_type')
                    ->label(__('admin.courses.fields.access_type'))
                    ->badge(),
                Tables\Columns\IconColumn::make('is_published')
                    ->label(__('admin.courses.fields.is_published'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('lessons_count')
                    ->counts('lessons')
                    ->label(__('admin.courses.table.lessons')),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('admin.common.fields.updated_at'))
                    ->dateTime(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('previewLessons')
                    ->label(__('admin.courses.actions.lessons'))
                    ->icon('heroicon-o-queue-list')
                    ->url(fn (Course $record): string => CourseResource::getUrl('edit', ['record' => $record]).'?activeRelationManager=0'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            LessonsRelationManager::class,
            CoursePlansRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCourses::route('/'),
            'create' => Pages\CreateCourse::route('/create'),
            'edit' => Pages\EditCourse::route('/{record}/edit'),
        ];
    }
}
