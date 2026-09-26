<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\ManageSalesChannels;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductReviewResource;
use App\Models\User;
use App\Support\AdminLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Tests\TestCase;

final class AdminLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    public function test_arabic_admin_locale_resolves_rtl(): void
    {
        $this->assertSame('rtl', AdminLocale::direction('ar'));
        $this->assertTrue(AdminLocale::isRtl('ar'));

        app()->setLocale('ar');
        $this->assertSame('rtl', __('filament-panels::layout.direction'));
        $this->assertSame('rtl', AdminLocale::direction());
    }

    public function test_english_admin_locale_resolves_ltr(): void
    {
        $this->assertSame('ltr', AdminLocale::direction('en'));
        $this->assertFalse(AdminLocale::isRtl('en'));

        app()->setLocale('en');
        $this->assertSame('ltr', __('filament-panels::layout.direction'));
        $this->assertSame('ltr', AdminLocale::direction());
    }

    public function test_arabic_navigation_labels(): void
    {
        app()->setLocale('ar');

        $this->assertSame('المنتجات', __('admin.nav.products'));
        $this->assertSame('التصنيفات', __('admin.nav.categories'));
        $this->assertSame('تقييمات المنتجات', __('admin.nav.product_reviews'));
        $this->assertSame('الطلبات', __('admin.nav.orders'));
        $this->assertSame('كوبونات الخصم', __('admin.nav.coupons'));
        $this->assertSame('قنوات البيع', __('admin.nav.sales_channels'));
        $this->assertSame('التعلّم', __('admin.nav.groups.learning'));
        $this->assertSame('التقييم', __('admin.nav.groups.assessment'));
        $this->assertSame('الكتالوج', __('admin.nav.groups.catalog'));
        $this->assertSame('موافق عليه', __('admin.product_reviews.statuses.approved'));
        $this->assertSame('مرفوض', __('admin.product_reviews.statuses.rejected'));
        $this->assertSame('قيد الانتظار', __('admin.product_reviews.statuses.pending'));

        $this->assertSame('الكتالوج', ProductResource::getNavigationGroup());
        $this->assertSame('تقييمات المنتجات', ProductReviewResource::getNavigationLabel());
    }

    public function test_english_navigation_labels(): void
    {
        app()->setLocale('en');

        $this->assertSame('Products', __('admin.nav.products'));
        $this->assertSame('Categories', __('admin.nav.categories'));
        $this->assertSame('Product reviews', __('admin.nav.product_reviews'));
        $this->assertSame('Orders', __('admin.nav.orders'));
        $this->assertSame('Coupons', __('admin.nav.coupons'));
        $this->assertSame('Sales channels', __('admin.nav.sales_channels'));
        $this->assertSame('Learning', __('admin.nav.groups.learning'));
        $this->assertSame('Assessment', __('admin.nav.groups.assessment'));

        $this->assertSame('Catalog', ProductResource::getNavigationGroup());
        $this->assertSame('Product reviews', ProductReviewResource::getNavigationLabel());
    }

    public function test_sales_channels_arabic_labels(): void
    {
        app()->setLocale('ar');

        $this->assertSame('قنوات البيع', __('sales_channels.title'));
        $this->assertSame('إعداد القناة', __('sales_channels.actions.setup_channel'));
        $this->assertSame('مزامنة الآن', __('sales_channels.actions.sync_now'));
        $this->assertSame('يحتاج متابعة', __('sales_channels.health.needs_attention'));
        $this->assertSame('متصل', __('sales_channels.connection.connected'));
        $this->assertSame('النشاط الأخير', __('sales_channels.activity.title'));
    }

    public function test_sales_channels_english_labels(): void
    {
        app()->setLocale('en');

        $this->assertSame('Sales Channels', __('sales_channels.title'));
        $this->assertSame('Setup Channel', __('sales_channels.actions.setup_channel'));
        $this->assertSame('Sync Now', __('sales_channels.actions.sync_now'));
        $this->assertSame('Needs Attention', __('sales_channels.health.needs_attention'));
        $this->assertSame('Connected', __('sales_channels.connection.connected'));
        $this->assertSame('Recent activity', __('sales_channels.activity.title'));
    }

    public function test_validation_messages_follow_locale(): void
    {
        app()->setLocale('ar');
        $ar = Validator::make(['email' => 'bad'], ['email' => 'required|email'])->errors()->first('email');
        $this->assertNotSame('', $ar);
        $this->assertStringNotContainsString('must be a valid email', strtolower($ar));

        app()->setLocale('en');
        $en = Validator::make(['email' => 'bad'], ['email' => 'required|email'])->errors()->first('email');
        $this->assertNotSame('', $en);
        $this->assertNotSame($ar, $en);
    }

    public function test_language_switch_persists_in_session_and_user(): void
    {
        $admin = User::factory()->create(['locale' => 'en']);
        $admin->assignRole('super_admin');

        $this->actingAs($admin)
            ->get(route('admin.locale.switch', ['locale' => 'ar']))
            ->assertRedirect();

        $this->assertSame('ar', session(AdminLocale::SESSION_KEY));
        $this->assertSame('ar', $admin->fresh()->locale);
        $this->assertSame('ar', app()->getLocale());

        $this->actingAs($admin)
            ->get(route('admin.locale.switch', ['locale' => 'en']))
            ->assertRedirect();

        $this->assertSame('en', session(AdminLocale::SESSION_KEY));
        $this->assertSame('en', $admin->fresh()->locale);
    }

    public function test_technical_ltr_fields_marked_on_product_form(): void
    {
        app()->setLocale('ar');

        $this->assertSame('rtl', AdminLocale::direction());
        $this->assertSame('المنتجات', ProductResource::getNavigationLabel());
        $this->assertSame('معرّف الرابط', __('admin.common.fields.slug'));
        // Technical identifiers stay Latin-script / LTR even in Arabic UI copy.
        $this->assertSame('SKU', __('admin.common.fields.sku'));
    }

    public function test_unauthorized_staff_still_blocked_from_sales_channels(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('content_manager');

        Livewire::actingAs($staff)
            ->test(ManageSalesChannels::class)
            ->assertSuccessful()
            ->assertDontSee(__('sales_channels.actions.setup_channel'))
            ->call('openGoogleSetup')
            ->assertForbidden();
    }

    public function test_admin_en_and_ar_translation_keys_match(): void
    {
        $flatten = static function (array $array, string $prefix = '') use (&$flatten): array {
            $result = [];
            foreach ($array as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                if (is_array($value)) {
                    $result += $flatten($value, $path);
                } else {
                    $result[$path] = $value;
                }
            }

            return $result;
        };

        $en = $flatten(include lang_path('en/admin.php'));
        $ar = $flatten(include lang_path('ar/admin.php'));

        $this->assertSame([], array_values(array_diff_key($ar, $en)), 'Missing EN admin keys');
        $this->assertSame([], array_values(array_diff_key($en, $ar)), 'Missing AR admin keys');
        $this->assertGreaterThan(500, count($en));
    }
}
