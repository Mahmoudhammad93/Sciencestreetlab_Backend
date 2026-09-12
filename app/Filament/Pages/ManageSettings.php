<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Forms\Components\ImageDropzone;
use App\Services\SiteSettings;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * @property Form $form
 */
class ManageSettings extends Page
{
    use InteractsWithFormActions;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Website & dashboard settings';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.manage-settings';

    /**
     * @var array<string, mixed> | null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof \App\Models\User && $user->hasRole('super_admin');
    }

    public function mount(): void
    {
        $this->form->fill(SiteSettings::get());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('Settings')
                    ->persistTabInQueryString()
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Website')
                            ->icon('heroicon-o-globe-alt')
                            ->schema([
                                Forms\Components\Section::make('Brand')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('site_name_ar')
                                            ->label('Site name (Arabic)')
                                            ->required()
                                            ->maxLength(120),
                                        Forms\Components\TextInput::make('site_name_en')
                                            ->label('Site name (English)')
                                            ->required()
                                            ->maxLength(120),
                                        Forms\Components\Textarea::make('tagline_ar')
                                            ->label('Tagline (Arabic)')
                                            ->rows(2)
                                            ->columnSpanFull(),
                                        Forms\Components\Textarea::make('tagline_en')
                                            ->label('Tagline (English)')
                                            ->rows(2)
                                            ->columnSpanFull(),
                                        ImageDropzone::make(
                                            'logo_url',
                                            'brand',
                                            'Site logo',
                                            'Drag and drop the site logo here, or click to browse.'
                                        ),
                                    ]),
                                Forms\Components\Section::make('Website colors')
                                    ->description('These colors apply to the public storefront.')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\ColorPicker::make('primary_color')
                                            ->label('Primary')
                                            ->hex()
                                            ->required(),
                                        Forms\Components\ColorPicker::make('accent_color')
                                            ->label('Accent / yellow')
                                            ->hex()
                                            ->required(),
                                        Forms\Components\ColorPicker::make('navbar_color')
                                            ->label('Navbar color')
                                            ->helperText('Yellow menu bar background')
                                            ->hex()
                                            ->required(),
                                        Forms\Components\ColorPicker::make('navbar_text_color')
                                            ->label('Navbar text color')
                                            ->helperText('Menu links and icon contrast color')
                                            ->hex()
                                            ->required(),
                                    ]),
                                Forms\Components\Section::make('Contact')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('contact_email')
                                            ->email()
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('contact_phone')
                                            ->tel()
                                            ->maxLength(30),
                                        Forms\Components\TextInput::make('whatsapp')
                                            ->label('WhatsApp number')
                                            ->helperText('Digits only, with country code. Example: 201000000000')
                                            ->maxLength(20),
                                        Forms\Components\TextInput::make('address')
                                            ->maxLength(255),
                                    ]),
                                Forms\Components\Section::make('Social links')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('facebook_url')->url()->maxLength(255),
                                        Forms\Components\TextInput::make('instagram_url')->url()->maxLength(255),
                                        Forms\Components\TextInput::make('youtube_url')->url()->maxLength(255),
                                        Forms\Components\TextInput::make('tiktok_url')->url()->maxLength(255),
                                        Forms\Components\TextInput::make('linkedin_url')->url()->maxLength(255),
                                    ]),
                                Forms\Components\Section::make('Promo banner')
                                    ->schema([
                                        Forms\Components\Toggle::make('promo_banner_enabled')
                                            ->label('Show promo banner on the website'),
                                        Forms\Components\Textarea::make('promo_banner')
                                            ->label('Banner text')
                                            ->rows(2)
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                        Forms\Components\Tabs\Tab::make('Admin dashboard')
                            ->icon('heroicon-o-swatch')
                            ->schema([
                                Forms\Components\Section::make('Appearance')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('admin_brand_name')
                                            ->label('Admin brand name')
                                            ->required()
                                            ->maxLength(80),
                                        Forms\Components\Select::make('admin_theme_mode')
                                            ->label('Default theme')
                                            ->options([
                                                'system' => 'System',
                                                'light' => 'Light',
                                                'dark' => 'Dark',
                                            ])
                                            ->required(),
                                        Forms\Components\ColorPicker::make('admin_primary_color')
                                            ->label('Primary color')
                                            ->hex()
                                            ->required(),
                                        Forms\Components\ColorPicker::make('admin_accent_color')
                                            ->label('Accent / warning color')
                                            ->hex()
                                            ->required(),
                                    ]),
                                Forms\Components\Section::make('Layout')
                                    ->description('Choose how wide the admin content wrapper should be.')
                                    ->schema([
                                        Forms\Components\ToggleButtons::make('admin_layout')
                                            ->label('Content wrapper')
                                            ->helperText('Compact = narrow box. Container = standard boxed width. Fluid = full width.')
                                            ->options([
                                                'compact' => 'Compact',
                                                'container' => 'Container',
                                                'fluid' => 'Fluid',
                                            ])
                                            ->icons([
                                                'compact' => 'heroicon-o-view-columns',
                                                'container' => 'heroicon-o-square-2-stack',
                                                'fluid' => 'heroicon-o-arrows-pointing-out',
                                            ])
                                            ->inline()
                                            ->required(),
                                        Forms\Components\Toggle::make('admin_sidebar_collapsible')
                                            ->label('Collapsible sidebar on desktop')
                                            ->inline(false),
                                    ]),
                                Forms\Components\Section::make('Dashboard text')
                                    ->columns(1)
                                    ->schema([
                                        Forms\Components\TextInput::make('dashboard_heading')
                                            ->label('Dashboard heading')
                                            ->required()
                                            ->maxLength(120),
                                        Forms\Components\TextInput::make('dashboard_subheading')
                                            ->label('Dashboard subheading')
                                            ->maxLength(200),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $existing = SiteSettings::get();

        // Keep previous logo when the dropzone is empty (e.g. external URL still in use).
        if (blank($state['logo_url'] ?? null) && filled($existing['logo_url'] ?? null)) {
            $state['logo_url'] = $existing['logo_url'];
        }

        $state['primary_color'] = SiteSettings::normalizeHex((string) ($state['primary_color'] ?? ''), '#2828a0');
        $state['accent_color'] = SiteSettings::normalizeHex((string) ($state['accent_color'] ?? ''), '#fcd500');
        $state['navbar_color'] = SiteSettings::normalizeHex((string) ($state['navbar_color'] ?? ''), '#fcd500');
        $state['navbar_text_color'] = SiteSettings::normalizeHex((string) ($state['navbar_text_color'] ?? ''), '#3030d0');
        $state['admin_primary_color'] = SiteSettings::normalizeHex((string) ($state['admin_primary_color'] ?? ''), '#2828a0');
        $state['admin_accent_color'] = SiteSettings::normalizeHex((string) ($state['admin_accent_color'] ?? ''), '#fcd500');

        SiteSettings::save($state);

        Notification::make()
            ->title('Settings saved')
            ->body('Reload the admin panel to apply color and layout changes.')
            ->success()
            ->send();

        $this->redirect(static::getUrl());
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save settings')
                ->submit('save')
                ->keyBindings(['mod+s']),
        ];
    }
}
