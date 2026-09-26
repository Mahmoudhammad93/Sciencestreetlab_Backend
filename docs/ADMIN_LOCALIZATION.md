# Admin Localization

ScienceStreetLab Filament admin supports **Arabic (RTL)** and **English (LTR)**.

## Supported locales

| Locale | Direction | HTML |
|--------|-----------|------|
| `ar`   | RTL       | `lang="ar" dir="rtl"` |
| `en`   | LTR       | `lang="en" dir="ltr"` |

Direction is **never** hardcoded globally. It always follows the active admin locale.

## Locale detection

Central helper: `App\Support\AdminLocale`

Resolution order for admin requests (`SetAdminLocale` middleware):

1. Session key `admin_locale`
2. Authenticated user `locale` preference (if set)
3. `config('app.locale')`

Public API `Accept-Language` handling (`SetLocale`) is unchanged and separate.

## Direction resolution

```php
AdminLocale::direction(); // 'rtl' | 'ltr'
AdminLocale::isRtl();
```

Filament v3 reads `__('filament-panels::layout.direction')`:

- Arabic vendor pack → `rtl` (sidebar on the **right**)
- English vendor pack → `ltr` (sidebar on the **left**)

Setting `app()->setLocale(...)` is enough for sidebar, forms, tables, modals, notifications, pagination, breadcrumbs, and tabs.

## Language switcher

User menu items in `AdminPanelProvider`:

- **العربية** / Switch to Arabic → `route('admin.locale.switch', ['locale' => 'ar'])`
- **English** / Switch to English → `route('admin.locale.switch', ['locale' => 'en'])`

Controller: `App\Http\Controllers\Admin\SwitchAdminLocaleController`

- Persists to session (`admin_locale`)
- Updates `users.locale` when possible
- Reloads via `redirect()->back()` (no logout)

Route (auth + web only):

```
GET /admin/locale/{locale}  →  admin.locale.switch
```

## Translation file structure

| File | Purpose |
|------|---------|
| `lang/en/admin.php` | Admin UI (nav, resources, widgets, settings, …) |
| `lang/ar/admin.php` | Arabic equivalents (same keys) |
| `lang/en/sales_channels.php` | Sales Channels module |
| `lang/ar/sales_channels.php` | Arabic equivalents |
| `lang/*/validation.php` | Laravel validation messages |
| `lang/*/auth.php` | Auth messages |

Use:

```php
__('admin.nav.products')
__('admin.product_reviews.actions.approve')
__('sales_channels.actions.setup_channel')
```

### Adding translations

1. Add the key to **both** `lang/en/admin.php` and `lang/ar/admin.php`.
2. Prefer nested groups (`products.fields.*`, `common.actions.*`).
3. Reuse `admin.common.*` for shared labels (Save, Delete, Email, …).
4. Keep EN/AR key trees identical (run the key-audit check in tests).

## Technical LTR fields

Even when the page is Arabic/RTL, keep technical values LTR via:

```php
->extraInputAttributes(['dir' => 'ltr'])
```

or Blade `dir="ltr"` on inputs/previews.

Apply to: email, URL, domain, API key/token, SKU, slug, JSON, Merchant Center ID, service account email, project ID, phone numbers where appropriate.

Do **not** RTL-mirror brand logos or semantic icons (trash, star, Google/YouTube marks).

## Filament considerations

- Navigation groups/labels use `getNavigationGroup()` / `getNavigationLabel()` returning `__('admin.nav.*')`.
- Panel `navigationGroups` use `NavigationGroup::make()->label(fn () => __('…'))` so labels resolve after locale middleware.
- Wizards/step UI (Sales Channels) inherit document direction; Next/Previous labels come from translations.
- Custom CSS should prefer logical properties (`margin-inline-start`, `padding-inline-end`, `text-start`) when adding new directional rules. Flex `flex-start` / `flex-end` already follows writing mode.

## Dynamic / database content

| Kind | Translate? |
|------|------------|
| UI chrome (labels, buttons, empty states) | Yes — `__()` |
| Localized product/course fields (`name.ar` / `name.en`) | Use existing Spatie/translatable fields |
| Technical IDs, SKUs, API keys, URLs | Never |
| Brand names (Google Merchant Center, Bosta, …) | Keep as-is |

## Testing

Focused suite:

```bash
php artisan test --filter=AdminLocalizationTest
```

Covers:

1. AR → RTL / EN → LTR
2. Navigation labels (AR + EN)
3. Sales Channels labels (AR + EN)
4. Validation locale
5. Language switch persistence
6. Unauthorized Sales Channels access unchanged
7. EN/AR admin key parity

Also keep Sales Channels feature tests green:

```bash
php artisan test tests/Feature/SocialCommerce/SalesChannelsTest.php
```

## Related docs

- `docs/SALES_CHANNELS.md` — Sales Channels product behavior
- `docs/EGYPT_BOSTA_SHIPPING_FRONTEND.md` — shipping (business logic; not localization)
