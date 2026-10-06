<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Modules\Commerce\Application\Support\MicroscopePurchaseOptions;
use App\Modules\Commerce\Infrastructure\Persistence\Models\Order;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.groups.commerce');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.nav.orders');
    }

    public static function getModelLabel(): string
    {
        return __('admin.nav.orders');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.nav.orders');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('order_number')->disabled(),
            Forms\Components\Select::make('status')
                ->options(function (?Order $record): array {
                    $options = [
                        'pending' => 'Pending',
                        'awaiting_payment' => 'Awaiting Payment',
                        'paid' => 'Paid',
                        'processing' => 'Processing',
                        'shipped' => 'Shipped',
                        'cancelled' => 'Cancelled',
                        'refunded' => 'Refunded',
                    ];

                    // Delivered is an explicit fulfillment action for delivery-gated orders
                    // that are not yet fulfilled — prevents silent split-brain via Save.
                    $allowDeliveredInSelect = $record === null
                        || $record->status === 'delivered'
                        || $record->fulfilled_at !== null
                        || ! (bool) $record->requires_delivery_fulfillment;

                    if ($allowDeliveredInSelect) {
                        $options = [
                            'pending' => 'Pending',
                            'awaiting_payment' => 'Awaiting Payment',
                            'paid' => 'Paid',
                            'processing' => 'Processing',
                            'shipped' => 'Shipped',
                            'delivered' => 'Delivered',
                            'cancelled' => 'Cancelled',
                            'refunded' => 'Refunded',
                        ];
                    }

                    return $options;
                })
                ->helperText(function (?Order $record): ?string {
                    if ($record !== null
                        && (bool) $record->requires_delivery_fulfillment
                        && $record->fulfilled_at === null
                        && $record->status !== 'delivered'
                    ) {
                        return __('admin.orders.fields.status_delivered_via_action_help');
                    }

                    return null;
                })
                ->required(),
            Forms\Components\TextInput::make('total')->numeric()->prefix('EGP')->disabled(),
            Forms\Components\Textarea::make('notes'),
            Forms\Components\Section::make(__('admin.orders.sections.customer'))
                ->description(__('admin.orders.sections.customer_description'))
                ->schema([
                    Forms\Components\Placeholder::make('customer_type')
                        ->label(__('admin.orders.fields.customer_type'))
                        ->content(function (?Order $record): string {
                            if ($record === null) {
                                return '—';
                            }

                            return $record->is_guest
                                ? __('admin.common.fields.guest')
                                : __('admin.orders.fields.registered_customer');
                        }),
                    Forms\Components\Placeholder::make('customer_account')
                        ->label(__('admin.orders.fields.account'))
                        ->content(function (?Order $record): HtmlString|string {
                            if ($record === null) {
                                return '—';
                            }
                            $record->loadMissing('user');
                            $user = $record->user;
                            if ($user === null) {
                                return $record->is_guest
                                    ? __('admin.orders.placeholders.guest_no_account')
                                    : '—';
                            }

                            $label = trim((string) $user->name);
                            if ($label === '') {
                                $label = '#'.$user->id;
                            }

                            try {
                                $url = UserResource::getUrl('edit', ['record' => $user]);

                                return new HtmlString(
                                    '<a href="'.e($url).'" class="text-primary-600 underline font-medium">'
                                    .e($label)
                                    .'</a>'
                                    .' <span class="text-gray-500">(ID: '.e((string) $user->id).')</span>'
                                );
                            } catch (\Throwable) {
                                return $label.' (ID: '.$user->id.')';
                            }
                        }),
                    Forms\Components\Placeholder::make('customer_email')
                        ->label(__('admin.common.fields.email'))
                        ->content(fn (?Order $record): string => self::customerEmail($record)),
                    Forms\Components\Placeholder::make('customer_phone')
                        ->label(__('admin.common.fields.phone'))
                        ->content(fn (?Order $record): string => self::customerPhone($record)),
                    Forms\Components\Placeholder::make('billing_name')
                        ->label(__('admin.orders.fields.billing_name'))
                        ->content(fn (?Order $record): string => self::addressFullName($record?->billing_address)),
                    Forms\Components\Placeholder::make('billing_contact')
                        ->label(__('admin.orders.fields.billing_contact'))
                        ->content(fn (?Order $record): string => self::addressContactLine($record?->billing_address)),
                    Forms\Components\Placeholder::make('billing_address_display')
                        ->label(__('admin.orders.fields.billing_address'))
                        ->content(fn (?Order $record): HtmlString|string => self::formatAddressHtml($record?->billing_address)),
                    Forms\Components\Placeholder::make('shipping_name')
                        ->label(__('admin.orders.fields.shipping_name'))
                        ->content(fn (?Order $record): string => self::addressFullName($record?->shipping_address)),
                    Forms\Components\Placeholder::make('shipping_contact')
                        ->label(__('admin.orders.fields.shipping_contact'))
                        ->content(fn (?Order $record): string => self::addressContactLine($record?->shipping_address)),
                    Forms\Components\Placeholder::make('shipping_address_display')
                        ->label(__('admin.orders.fields.shipping_address'))
                        ->content(fn (?Order $record): HtmlString|string => self::formatAddressHtml($record?->shipping_address))
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->collapsed(false),
            Forms\Components\Section::make(__('admin.orders.sections.items', ['default' => 'Order items']))
                ->schema([
                    Forms\Components\Placeholder::make('order_items_display')
                        ->label('')
                        ->content(function (?Order $record): string {
                            if ($record === null) {
                                return '—';
                            }
                            $record->loadMissing('items');
                            if ($record->items->isEmpty()) {
                                return '—';
                            }
                            $lines = [];
                            foreach ($record->items as $item) {
                                $line = trim((string) $item->product_name).' × '.(int) $item->quantity;
                                $lang = MicroscopePurchaseOptions::bookLanguageFromMetadata(
                                    is_array($item->metadata) ? $item->metadata : null
                                );
                                if ($lang !== null) {
                                    $line .= ' | '.__('admin.orders.fields.book_language', ['default' => 'Book']).': '
                                        .MicroscopePurchaseOptions::displayBookLanguage($lang);
                                }
                                $lines[] = $line;
                            }

                            return implode("\n", $lines);
                        }),
                ])
                ->collapsed(false),
            Forms\Components\Section::make(__('admin.orders.sections.bosta'))
                ->description(__('admin.orders.sections.bosta_description'))
                ->schema([
                    Forms\Components\Placeholder::make('order_status_display')
                        ->label(__('admin.orders.fields.order_status'))
                        ->content(fn (?Order $record): string => (string) ($record?->status ?? '—')),
                    Forms\Components\Placeholder::make('bosta_status')
                        ->label(__('admin.orders.fields.local_shipment_status'))
                        ->content(fn (?Order $record): string => $record?->bostaShipment?->status?->label()
                            ?? ($record?->requires_delivery_fulfillment
                                ? __('admin.orders.placeholders.course_gate_pending')
                                : __('admin.orders.placeholders.course_gate_not_bosta'))),
                    Forms\Components\Placeholder::make('bosta_provider_status')
                        ->label(__('admin.orders.fields.bosta_provider_status'))
                        ->content(function (?Order $record): string {
                            $code = (string) ($record?->bostaShipment?->provider_status ?: '');
                            if ($code === '') {
                                return '—';
                            }
                            $label = $record?->bostaShipment?->status?->label();

                            return $label ? $code.' ('.$label.')' : $code;
                        }),
                    Forms\Components\Placeholder::make('bosta_last_status_source')
                        ->label(__('admin.orders.fields.last_status_source'))
                        ->content(fn (?Order $record): string => (string) ($record?->bostaShipment?->metadata['last_status_source'] ?? '—')),
                    Forms\Components\Placeholder::make('bosta_tracking')
                        ->label(__('admin.orders.fields.tracking_number'))
                        ->content(fn (?Order $record): string => $record?->bostaShipment?->tracking_number ?: '—'),
                    Forms\Components\Placeholder::make('bosta_external')
                        ->label(__('admin.orders.fields.external_shipment_id'))
                        ->content(fn (?Order $record): string => $record?->bostaShipment?->external_shipment_id ?: '—'),
                    Forms\Components\Placeholder::make('bosta_last_webhook')
                        ->label(__('admin.orders.fields.last_webhook_at'))
                        ->content(fn (?Order $record): string => $record?->bostaShipment?->last_webhook_at
                            ? $record->bostaShipment->last_webhook_at->timezone(config('sciencestreet.timezone', config('app.timezone')))->toDateTimeString()
                            : '—'),
                    Forms\Components\Placeholder::make('bosta_last_sync')
                        ->label(__('admin.orders.fields.last_bosta_sync_at'))
                        ->content(function (?Order $record): string {
                            $at = $record?->bostaShipment?->metadata['last_status_synced_at'] ?? null;
                            if (! is_string($at) || $at === '') {
                                return '—';
                            }
                            try {
                                return \Illuminate\Support\Carbon::parse($at)
                                    ->timezone(config('sciencestreet.timezone', config('app.timezone')))
                                    ->toDateTimeString();
                            } catch (\Throwable) {
                                return $at;
                            }
                        }),
                    Forms\Components\Placeholder::make('course_gate')
                        ->label(__('admin.orders.fields.course_unlock'))
                        ->content(fn (?Order $record): string => $record?->fulfilled_at
                            ? __('admin.orders.placeholders.course_gate_active')
                            : ($record?->requires_delivery_fulfillment
                                ? __('admin.orders.placeholders.course_gate_locked')
                                : __('admin.orders.placeholders.course_gate_not_gated'))),
                ])
                ->columns(2)
                ->collapsed(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_number')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label(__('admin.common.fields.customer')),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('total')->money('EGP')->sortable(),
                Tables\Columns\TextColumn::make('paid_at')->dateTime(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }

    private static function customerEmail(?Order $record): string
    {
        if ($record === null) {
            return '—';
        }
        $record->loadMissing('user');
        $fromUser = trim((string) ($record->user?->email ?? ''));
        if ($fromUser !== '') {
            return $fromUser;
        }
        $fromBilling = trim((string) (self::addressArray($record->billing_address)['email'] ?? ''));

        return $fromBilling !== '' ? $fromBilling : '—';
    }

    private static function customerPhone(?Order $record): string
    {
        if ($record === null) {
            return '—';
        }
        $record->loadMissing('user');
        $fromUser = trim((string) ($record->user?->phone ?? ''));
        if ($fromUser !== '') {
            return $fromUser;
        }
        $billing = self::addressArray($record->billing_address);
        $shipping = self::addressArray($record->shipping_address);
        $fromAddress = trim((string) ($billing['phone'] ?? $shipping['phone'] ?? ''));

        return $fromAddress !== '' ? $fromAddress : '—';
    }

    /**
     * @param  array<string, mixed>|null  $address
     */
    private static function addressFullName(mixed $address): string
    {
        $data = self::addressArray($address);
        $name = trim(((string) ($data['first_name'] ?? '')).' '.((string) ($data['last_name'] ?? '')));

        return $name !== '' ? $name : '—';
    }

    /**
     * @param  array<string, mixed>|null  $address
     */
    private static function addressContactLine(mixed $address): string
    {
        $data = self::addressArray($address);
        $parts = array_values(array_filter([
            trim((string) ($data['email'] ?? '')),
            trim((string) ($data['phone'] ?? '')),
        ], static fn (string $value): bool => $value !== ''));

        return $parts === [] ? '—' : implode(' · ', $parts);
    }

    /**
     * @param  array<string, mixed>|null  $address
     */
    private static function formatAddressHtml(mixed $address): HtmlString|string
    {
        $data = self::addressArray($address);
        if ($data === []) {
            return '—';
        }

        $district = trim((string) ($data['district'] ?? $data['district_name'] ?? ''));
        $lines = array_values(array_filter([
            trim((string) ($data['address'] ?? '')),
            $district,
            trim((string) ($data['city'] ?? '')),
            trim((string) ($data['country'] ?? '')),
        ], static fn (string $value): bool => $value !== ''));

        if ($lines === []) {
            return '—';
        }

        return new HtmlString(implode('<br>', array_map(static fn (string $line): string => e($line), $lines)));
    }

    /**
     * @return array<string, mixed>
     */
    private static function addressArray(mixed $address): array
    {
        return is_array($address) ? $address : [];
    }
}
