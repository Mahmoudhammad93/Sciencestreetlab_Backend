<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Forms\Components\ImageDropzone;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.groups.identity');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.nav.users');
    }

    public static function getModelLabel(): string
    {
        return __('admin.nav.users');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.nav.users');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('admin.users.sections.account'))->schema([
                Forms\Components\TextInput::make('name')
                    ->label(__('admin.common.fields.name'))
                    ->required(),
                Forms\Components\TextInput::make('email')
                    ->label(__('admin.common.fields.email'))
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->extraInputAttributes(['dir' => 'ltr']),
                Forms\Components\TextInput::make('phone')
                    ->label(__('admin.common.fields.phone'))
                    ->tel()
                    ->extraInputAttributes(['dir' => 'ltr']),
                Forms\Components\Select::make('locale')
                    ->label(__('admin.locale.current_language'))
                    ->options([
                        'ar' => __('admin.users.options.locale.ar'),
                        'en' => __('admin.users.options.locale.en'),
                    ])
                    ->default('ar'),
                Forms\Components\TextInput::make('password')
                    ->password()
                    ->dehydrateStateUsing(fn (?string $state) => filled($state) ? Hash::make($state) : null)
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->required(fn (string $operation) => $operation === 'create'),
                Forms\Components\Select::make('roles')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload(),
                Forms\Components\Toggle::make('is_active')->default(true),
            ])->columns(2),
            Forms\Components\Section::make(__('admin.users.sections.avatar'))->schema([
                ImageDropzone::make(
                    'avatar_path',
                    'avatars',
                    __('admin.users.fields.avatar'),
                    __('admin.users.fields.avatar_help')
                ),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('avatar_path')
                    ->label(__('admin.users.table.avatar'))
                    ->getStateUsing(fn (User $record): ?string => ImageDropzone::publicUrl($record->avatar_path))
                    ->circular(),
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('email')->searchable(),
                Tables\Columns\TextColumn::make('roles.name')->badge(),
                Tables\Columns\TextColumn::make('locale'),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->dateTime(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
