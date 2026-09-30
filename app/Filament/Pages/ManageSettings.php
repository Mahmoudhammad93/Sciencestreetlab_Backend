<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Forms\Components\ImageDropzone;
use App\Modules\Content\Infrastructure\Persistence\Models\HomeSlide;
use App\Services\SiteSettings;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
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

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.manage-settings';

    /**
     * @var array<string, mixed> | null
     */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.nav.settings');
    }

    public function getTitle(): string
    {
        return __('admin.settings.page_title');
    }

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
                Forms\Components\Tabs::make(__('admin.settings.tabs.root'))
                    ->persistTabInQueryString()
                    ->tabs([
                        Forms\Components\Tabs\Tab::make(__('admin.settings.tabs.website'))
                            ->icon('heroicon-o-globe-alt')
                            ->schema([
                                Forms\Components\Section::make(__('admin.settings.sections.brand'))
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('site_name_ar')
                                            ->label(__('admin.settings.fields.site_name_ar'))
                                            ->required()
                                            ->maxLength(120),
                                        Forms\Components\TextInput::make('site_name_en')
                                            ->label(__('admin.settings.fields.site_name_en'))
                                            ->required()
                                            ->maxLength(120),
                                        Forms\Components\Textarea::make('tagline_ar')
                                            ->label(__('admin.settings.fields.tagline_ar'))
                                            ->rows(2)
                                            ->columnSpanFull(),
                                        Forms\Components\Textarea::make('tagline_en')
                                            ->label(__('admin.settings.fields.tagline_en'))
                                            ->rows(2)
                                            ->columnSpanFull(),
                                        ImageDropzone::make(
                                            'logo_url',
                                            'brand',
                                            __('admin.settings.fields.logo.label'),
                                            __('admin.settings.fields.logo.helper')
                                        ),
                                        Forms\Components\Placeholder::make('logo_url_preview')
                                            ->label(__('admin.settings.fields.logo_current'))
                                            ->content(function (): \Illuminate\Support\HtmlString {
                                                $logo = SiteSettings::get()['logo_url'] ?? null;
                                                $url = ImageDropzone::publicUrl(is_string($logo) ? $logo : null);

                                                if (blank($url)) {
                                                    return new \Illuminate\Support\HtmlString(
                                                        '<span class="text-sm text-gray-500">'.e(__('admin.settings.fields.logo_empty')).'</span>'
                                                    );
                                                }

                                                $safe = e($url);

                                                return new \Illuminate\Support\HtmlString(
                                                    '<div class="flex items-center gap-3">'
                                                    .'<img src="'.$safe.'" alt="'.e(__('admin.settings.fields.logo.label')).'" class="h-14 w-auto rounded-md border border-gray-200 bg-white object-contain p-1 dark:border-gray-700" />'
                                                    .'<a href="'.$safe.'" target="_blank" rel="noopener" class="text-sm text-primary-600 hover:underline">'.e(__('admin.settings.fields.logo_open_link')).'</a>'
                                                    .'</div>'
                                                );
                                            })
                                            ->columnSpanFull(),
                                    ]),
                                Forms\Components\Section::make(__('admin.settings.sections.website_colors'))
                                    ->description(__('admin.settings.sections.website_colors_description'))
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\ColorPicker::make('primary_color')
                                            ->label(__('admin.settings.fields.primary_color'))
                                            ->hex()
                                            ->required(),
                                        Forms\Components\ColorPicker::make('accent_color')
                                            ->label(__('admin.settings.fields.accent_color'))
                                            ->hex()
                                            ->required(),
                                        Forms\Components\ColorPicker::make('navbar_color')
                                            ->label(__('admin.settings.fields.navbar_color'))
                                            ->helperText(__('admin.settings.fields.navbar_color_help'))
                                            ->hex()
                                            ->required(),
                                        Forms\Components\ColorPicker::make('navbar_text_color')
                                            ->label(__('admin.settings.fields.navbar_text_color'))
                                            ->helperText(__('admin.settings.fields.navbar_text_color_help'))
                                            ->hex()
                                            ->required(),
                                    ]),
                                Forms\Components\Section::make(__('admin.settings.sections.contact'))
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('contact_email')
                                            ->label(__('admin.settings.fields.contact_email'))
                                            ->email()
                                            ->maxLength(255)
                                            ->extraInputAttributes(['dir' => 'ltr']),
                                        Forms\Components\TextInput::make('contact_phone')
                                            ->label(__('admin.settings.fields.contact_phone'))
                                            ->tel()
                                            ->maxLength(30)
                                            ->extraInputAttributes(['dir' => 'ltr']),
                                        Forms\Components\TextInput::make('whatsapp')
                                            ->label(__('admin.settings.fields.whatsapp'))
                                            ->helperText(__('admin.settings.fields.whatsapp_help'))
                                            ->maxLength(20)
                                            ->extraInputAttributes(['dir' => 'ltr']),
                                        Forms\Components\TextInput::make('address')
                                            ->label(__('admin.common.fields.address'))
                                            ->maxLength(255),
                                    ]),
                                Forms\Components\Section::make(__('admin.settings.sections.social'))
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('facebook_url')
                                            ->label(__('admin.settings.fields.facebook_url'))
                                            ->url()
                                            ->maxLength(255)
                                            ->extraInputAttributes(['dir' => 'ltr']),
                                        Forms\Components\TextInput::make('instagram_url')
                                            ->label(__('admin.settings.fields.instagram_url'))
                                            ->url()
                                            ->maxLength(255)
                                            ->extraInputAttributes(['dir' => 'ltr']),
                                        Forms\Components\TextInput::make('youtube_url')
                                            ->label(__('admin.settings.fields.youtube_url'))
                                            ->url()
                                            ->maxLength(255)
                                            ->extraInputAttributes(['dir' => 'ltr']),
                                        Forms\Components\TextInput::make('tiktok_url')
                                            ->label(__('admin.settings.fields.tiktok_url'))
                                            ->url()
                                            ->maxLength(255)
                                            ->extraInputAttributes(['dir' => 'ltr']),
                                        Forms\Components\TextInput::make('linkedin_url')
                                            ->label(__('admin.settings.fields.linkedin_url'))
                                            ->url()
                                            ->maxLength(255)
                                            ->extraInputAttributes(['dir' => 'ltr']),
                                    ]),
                                Forms\Components\Section::make(__('admin.settings.sections.promo_banner'))
                                    ->schema([
                                        Forms\Components\Toggle::make('promo_banner_enabled')
                                            ->label(__('admin.settings.fields.promo_banner_enabled')),
                                        Forms\Components\Textarea::make('promo_banner')
                                            ->label(__('admin.settings.fields.promo_banner_text'))
                                            ->rows(2)
                                            ->columnSpanFull(),
                                    ]),
                                Forms\Components\Section::make(__('admin.settings.sections.home_slider'))
                                    ->description(__('admin.settings.sections.home_slider_description'))
                                    ->schema([
                                        Forms\Components\Select::make('home_slider_transition')
                                            ->label(__('admin.settings.fields.home_slider_transition'))
                                            ->helperText(__('admin.settings.fields.home_slider_transition_help'))
                                            ->options(collect(SiteSettings::HOME_SLIDER_TRANSITIONS)->mapWithKeys(
                                                fn (string $key): array => [$key => __('admin.settings.options.slider_transition.'.$key)]
                                            )->all())
                                            ->default('glitch')
                                            ->required()
                                            ->native(false)
                                            ->live(),
                                        Forms\Components\ViewField::make('home_slider_transition_preview')
                                            ->label(__('admin.settings.fields.home_slider_transition_preview'))
                                            ->helperText(__('admin.settings.fields.home_slider_transition_preview_help'))
                                            ->view('filament.forms.components.home-slider-transition-preview')
                                            ->viewData(fn (Get $get): array => [
                                                'transition' => SiteSettings::normalizeHomeSliderTransition(
                                                    (string) ($get('home_slider_transition') ?? 'glitch')
                                                ),
                                                'slides' => self::homeSliderPreviewSlides(),
                                            ])
                                            ->dehydrated(false),
                                    ]),
                            ]),
                        Forms\Components\Tabs\Tab::make(__('admin.settings.tabs.admin_dashboard'))
                            ->icon('heroicon-o-swatch')
                            ->schema([
                                Forms\Components\Section::make(__('admin.settings.sections.appearance'))
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('admin_brand_name')
                                            ->label(__('admin.settings.fields.admin_brand_name'))
                                            ->required()
                                            ->maxLength(80),
                                        Forms\Components\Select::make('admin_theme_mode')
                                            ->label(__('admin.settings.fields.admin_theme_mode'))
                                            ->options([
                                                'system' => __('admin.settings.options.theme.system'),
                                                'light' => __('admin.settings.options.theme.light'),
                                                'dark' => __('admin.settings.options.theme.dark'),
                                            ])
                                            ->required(),
                                        Forms\Components\ColorPicker::make('admin_primary_color')
                                            ->label(__('admin.settings.fields.admin_primary_color'))
                                            ->hex()
                                            ->required(),
                                        Forms\Components\ColorPicker::make('admin_accent_color')
                                            ->label(__('admin.settings.fields.admin_accent_color'))
                                            ->hex()
                                            ->required(),
                                    ]),
                                Forms\Components\Section::make(__('admin.settings.sections.layout'))
                                    ->description(__('admin.settings.sections.layout_description'))
                                    ->schema([
                                        Forms\Components\ToggleButtons::make('admin_layout')
                                            ->label(__('admin.settings.fields.admin_layout'))
                                            ->helperText(__('admin.settings.fields.admin_layout_help'))
                                            ->options([
                                                'container' => __('admin.settings.options.layout.container'),
                                                'wide' => __('admin.settings.options.layout.wide'),
                                                'full' => __('admin.settings.options.layout.full'),
                                            ])
                                            ->icons([
                                                'container' => 'heroicon-o-square-2-stack',
                                                'wide' => 'heroicon-o-view-columns',
                                                'full' => 'heroicon-o-arrows-pointing-out',
                                            ])
                                            ->inline()
                                            ->required(),
                                        Forms\Components\Toggle::make('admin_sidebar_collapsible')
                                            ->label(__('admin.settings.fields.sidebar_collapsible'))
                                            ->inline(false),
                                    ]),
                                Forms\Components\Section::make(__('admin.settings.sections.dashboard_text'))
                                    ->columns(1)
                                    ->schema([
                                        Forms\Components\TextInput::make('dashboard_heading')
                                            ->label(__('admin.settings.fields.dashboard_heading'))
                                            ->required()
                                            ->maxLength(120),
                                        Forms\Components\TextInput::make('dashboard_subheading')
                                            ->label(__('admin.settings.fields.dashboard_subheading'))
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
        $state['admin_layout'] = SiteSettings::normalizeAdminLayout((string) ($state['admin_layout'] ?? 'container'));
        $state['home_slider_transition'] = SiteSettings::normalizeHomeSliderTransition(
            (string) ($state['home_slider_transition'] ?? 'glitch')
        );

        SiteSettings::save($state);

        Notification::make()
            ->title(__('admin.settings.notifications.saved_title'))
            ->body(__('admin.settings.notifications.saved_body'))
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
                ->label(__('admin.settings.actions.save'))
                ->submit('save')
                ->keyBindings(['mod+s']),
        ];
    }

    /**
     * Active homepage slides used by the settings transition preview.
     *
     * @return list<array{id: int, image_url: ?string, background_color: string}>
     */
    public static function homeSliderPreviewSlides(): array
    {
        return HomeSlide::query()
            ->active()
            ->ordered()
            ->limit(8)
            ->get(['id', 'image', 'background_color'])
            ->map(static fn (HomeSlide $slide): array => [
                'id' => (int) $slide->id,
                'image_url' => ImageDropzone::publicUrl($slide->image),
                'background_color' => (string) ($slide->background_color ?: '#4B208C'),
            ])
            ->values()
            ->all();
    }
}
