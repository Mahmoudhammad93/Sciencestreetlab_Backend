<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ProductReviewResource\Pages;
use App\Modules\Catalog\Application\Services\ProductReviewService;
use App\Modules\Catalog\Domain\Enums\ProductReviewStatus;
use App\Modules\Catalog\Infrastructure\Persistence\Models\ProductReview;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ProductReviewResource extends Resource
{
    protected static ?string $model = ProductReview::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.groups.catalog');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.product_reviews.nav');
    }

    public static function getModelLabel(): string
    {
        return __('admin.common.fields.review');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.product_reviews.nav');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('product.sku')
                ->label(__('admin.product_reviews.fields.product_sku'))
                ->disabled(),
            Forms\Components\TextInput::make('user.name')
                ->label(__('admin.common.fields.customer'))
                ->disabled(),
            Forms\Components\TextInput::make('rating')
                ->label(__('admin.product_reviews.fields.rating'))
                ->disabled(),
            Forms\Components\Textarea::make('review')
                ->label(__('admin.product_reviews.fields.review'))
                ->rows(5)
                ->disabled()
                ->columnSpanFull(),
            Forms\Components\TextInput::make('status')
                ->label(__('admin.product_reviews.fields.status'))
                ->disabled(),
            Forms\Components\DateTimePicker::make('approved_at')
                ->label(__('admin.product_reviews.fields.approved_at'))
                ->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('product.sku')
                    ->label(__('admin.product_reviews.fields.product'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('product.name')
                    ->label(__('admin.product_reviews.fields.product_name'))
                    ->limit(30)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label(__('admin.common.fields.customer'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('rating')
                    ->label(__('admin.product_reviews.fields.rating'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('review')
                    ->label(__('admin.product_reviews.fields.review'))
                    ->limit(60)
                    ->wrap(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.product_reviews.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (ProductReviewStatus|string $state): string => match ($state instanceof ProductReviewStatus ? $state : ProductReviewStatus::tryFrom((string) $state)) {
                        ProductReviewStatus::Pending => __('admin.product_reviews.statuses.pending'),
                        ProductReviewStatus::Approved => __('admin.product_reviews.statuses.approved'),
                        ProductReviewStatus::Rejected => __('admin.product_reviews.statuses.rejected'),
                        default => (string) $state,
                    })
                    ->color(fn (ProductReviewStatus|string $state): string => match ($state instanceof ProductReviewStatus ? $state : ProductReviewStatus::tryFrom((string) $state)) {
                        ProductReviewStatus::Pending => 'warning',
                        ProductReviewStatus::Approved => 'success',
                        ProductReviewStatus::Rejected => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('admin.product_reviews.fields.submitted'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('approved_at')
                    ->label(__('admin.product_reviews.fields.approved_at'))
                    ->dateTime()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('admin.product_reviews.fields.status'))
                    ->options(collect(ProductReviewStatus::cases())->mapWithKeys(
                        fn (ProductReviewStatus $status) => [
                            $status->value => __('admin.product_reviews.statuses.'.$status->value),
                        ]
                    )),
                Tables\Filters\SelectFilter::make('product_id')
                    ->label(__('admin.product_reviews.fields.product'))
                    ->relationship('product', 'sku')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('rating')
                    ->label(__('admin.product_reviews.fields.rating'))
                    ->options([
                        1 => '1',
                        2 => '2',
                        3 => '3',
                        4 => '4',
                        5 => '5',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('approve')
                    ->label(__('admin.product_reviews.actions.approve'))
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (ProductReview $record): bool => $record->status !== ProductReviewStatus::Approved)
                    ->action(function (ProductReview $record): void {
                        $admin = Auth::user();
                        if (! $admin instanceof \App\Models\User) {
                            return;
                        }
                        app(ProductReviewService::class)->approve($record, $admin);
                        Notification::make()
                            ->title(__('admin.product_reviews.notifications.approved'))
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->label(__('admin.product_reviews.actions.reject'))
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ProductReview $record): bool => $record->status !== ProductReviewStatus::Rejected)
                    ->action(function (ProductReview $record): void {
                        $admin = Auth::user();
                        app(ProductReviewService::class)->reject(
                            $record,
                            $admin instanceof \App\Models\User ? $admin : null
                        );
                        Notification::make()
                            ->title(__('admin.product_reviews.notifications.rejected'))
                            ->success()
                            ->send();
                    }),
                Tables\Actions\DeleteAction::make()
                    ->after(function (ProductReview $record): void {
                        app(ProductReviewService::class)->recalculateProductRatings((int) $record->product_id);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductReviews::route('/'),
            'view' => Pages\ViewProductReview::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
