<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Catalog\Domain\Enums\ProductStatus;
use App\Modules\Catalog\Domain\Enums\ProductType;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Flagship kit from https://sciencestreetlab.com/item/science-street-microscope-2/
 * — purchasable from shop/cart for any user.
 */
final class ScienceStreetMicroscopeProductSeeder extends Seeder
{
    private const IMAGE_URL = 'https://sciencestreetlab.com/wp-content/uploads/2026/01/Untitled_design-removebg-preview-Picsart-AiImageEnhancer.png';

    public function run(): void
    {
        $descriptionAr = <<<'HTML'
<p><strong>أول ميكروسكوب تعليمي رقمي بتكبير يصل إلى 1000×</strong></p>
<p>الميكروسكوب الوحيد التفاعلي في مصر… اكتشاف بلا حدود. مصمّم لاستكشاف العالم من حولك بطريقة تفاعلية تخليك تشوف تفاصيل مستحيل العين تشوفها — من خلايا النبات، للشعر على رجل النملة، لحد الجراثيم في فطر الخبز.</p>
<h3>عالم كامل مخفي… ومتاح لطفلك</h3>
<ul>
<li>الطفل يوجّهه ويدوّر ويكتشف بنفسه الكائنات الحية الموجودة في البيت أو الجنينة أو المطبخ</li>
<li>يزود فضول الطفل للعالم اللي حواليه</li>
<li>يخلي تعلم العلوم أمتع وأسهل</li>
</ul>
<h3>بيحوّل الأشياء اليومية لاكتشافات مذهلة</h3>
<ul>
<li>بتكبير 1000× الأطفال مش بس بيشوفوا… هم بيستكشفوا ويسجّلوا العالم المخفي جوه الأوراق والحشرات والصخور</li>
<li>يقدروا ياخدوا صور وفيديوهات علشان يعيدوا مشاهدة اكتشافاتهم</li>
<li>وصلة Type-C مع الميكروسكوب للمشاركة بسهولة على أي جهاز</li>
</ul>
<h3>بيتشحن… مش ببطاريات</h3>
<ul>
<li>استخدام متواصل ولسنين طويلة بدون بطاريات خارجية</li>
<li>يشحن بوصلة Type-C ويتوصل على الكمبيوتر لنقل الصور والفيديوهات</li>
<li>فتحة لكروت ذاكرة حتى 64 جيجابايت</li>
</ul>
<h3>رؤية واضحة وجودة عالية</h3>
<ul>
<li>صور وفيديوهات بجودة عالية (فيديو 1080p)</li>
<li>إضاءة قابلة للتحكم لشدة الملاحظة الدقيقة</li>
</ul>
<h3>مناسب لجميع الأعمار</h3>
<ul>
<li>من أول 4 سنين لحد 88 سنة</li>
<li>يجي معاه كتاب تعليمي مصور خاص بميكروسكوب شارع العلوم (عربي أو إنجليزي)</li>
<li>ومنهج STEM كامل: 45 سيشن أونلاين، اختبارات تفاعلية، وشرح استخدام الميكروسكوب وعالم الكائنات الحية والحشرات وجسم الإنسان والنباتات والبكتيريا والفطريات والبلورات والصخور</li>
</ul>
HTML;

        $descriptionEn = <<<'HTML'
<p><strong>The first educational digital microscope with up to 1000× magnification</strong></p>
<p>Egypt’s interactive microscope for endless discovery — explore plant cells, insect details, bread mold, and more. Capture photos and 1080p video, recharge via USB-C (no disposable batteries), and expand storage up to 64GB.</p>
<p>Includes an illustrated Science Street book (Arabic or English) and a full STEM curriculum: 45 online sessions, interactive quizzes, and lessons on microscope use, living kingdoms, insects, the human body, plants, bacteria &amp; fungi, crystals and rocks.</p>
HTML;

        $attrs = [
            'sku' => 'SS-MICRO-001',
            'slug' => 'science-street-microscope-2',
            'type' => ProductType::Kit,
            'status' => ProductStatus::Published,
            'price' => 2790,
            'compare_price' => 3720,
            'currency' => 'EGP',
            'stock_quantity' => 999,
            'manage_stock' => false,
            'is_featured' => true,
            'average_rating' => 4.64,
            'review_count' => 28,
            'course_id' => null,
            'course_plan_id' => null,
            'sort_order' => 1,
            'published_at' => now(),
            'name' => [
                'ar' => 'ميكروسكوب شارع العلوم',
                'en' => 'Science Street Microscope',
            ],
            'short_description' => [
                'ar' => 'أول ميكروسكوب تعليمي رقمي بتكبير يصل إلى 1000× — مع كتاب ومنهج STEM كامل',
                'en' => 'Educational digital microscope up to 1000× — with book and full STEM curriculum',
            ],
            'description' => [
                'ar' => $descriptionAr,
                'en' => $descriptionEn,
            ],
            'meta_title' => [
                'ar' => 'ميكروسكوب شارع العلوم',
                'en' => 'Science Street Microscope',
            ],
            'meta_description' => [
                'ar' => 'ميكروسكوب تعليمي رقمي تفاعلي بتكبير 1000× مع كتاب وكورس STEM — خصم لفترة محدودة',
                'en' => 'Interactive educational digital microscope 1000× with book and STEM course — limited offer',
            ],
        ];

        // Prefer the WP slug; also migrate the older LearningCatalog slug if present.
        $product = Product::query()->withTrashed()
            ->where(function ($query): void {
                $query->where('sku', 'SS-MICRO-001')
                    ->orWhereIn('slug', ['science-street-microscope-2', 'science-street-microscope']);
            })
            ->first();

        if ($product) {
            if ($product->trashed()) {
                $product->restore();
            }
            $product->fill($attrs)->save();
        } else {
            $product = Product::query()->create($attrs);
        }

        // Ensure no duplicate leftover under the old slug.
        Product::query()
            ->where('slug', 'science-street-microscope')
            ->where('id', '!=', $product->id)
            ->delete();

        $this->attachImage($product);

        $this->command?->info("Science Street Microscope ready (#{$product->id}, slug={$product->slug}, price={$product->price} EGP).");
    }

    private function attachImage(Product $product): void
    {
        if ($product->getFirstMedia('image')) {
            return;
        }

        $local = database_path('seeders/assets/science-street-microscope.png');

        try {
            if (is_file($local)) {
                $product->addMedia($local)
                    ->preservingOriginal()
                    ->usingFileName('science-street-microscope.png')
                    ->toMediaCollection('image');

                return;
            }

            $product->addMediaFromUrl(self::IMAGE_URL)
                ->usingFileName('science-street-microscope.png')
                ->toMediaCollection('image');
        } catch (\Throwable $e) {
            $this->command?->warn('Microscope image attach skipped: '.$e->getMessage());
        }
    }
}
