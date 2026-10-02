<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Commerce\Domain\Enums\ShippingLocationScope;
use App\Modules\Commerce\Infrastructure\Persistence\Models\ShippingRateGroup;
use App\Modules\Commerce\Infrastructure\Persistence\Models\ShippingRateLocation;
use Illuminate\Database\Seeder;

/**
 * Seeds owner-provided Egypt shipping rates mapped to official Bosta city IDs
 * from GET /api/v1/shipping/locations/cities (production snapshot 2026-10-02).
 *
 * UNMAPPED: North Sinai — not present in Bosta drop-off cities list.
 */
final class ShippingRateSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            [
                'code' => 'cairo_giza',
                'name' => ['ar' => 'القاهرة والجيزة', 'en' => 'Cairo & Giza'],
                'price' => 80,
                'sort_order' => 10,
                'cities' => [
                    ['id' => 'FceDyHXwpSYYF9zGW', 'name' => 'Cairo', 'name_ar' => 'القاهره'],
                    ['id' => '0064Qb0OgcA', 'name' => 'Giza', 'name_ar' => 'الجيزه'],
                ],
            ],
            [
                'code' => 'alexandria_beheira',
                'name' => ['ar' => 'الإسكندرية والبحيرة', 'en' => 'Alexandria & Beheira'],
                'price' => 85,
                'sort_order' => 20,
                'cities' => [
                    ['id' => 'Jrb6X6ucjiYgMP4T7', 'name' => 'Alexandria', 'name_ar' => 'الاسكندريه'],
                    ['id' => 'g3GchTSmCgR2JynsJ', 'name' => 'Behira', 'name_ar' => 'البحيره'],
                ],
            ],
            [
                'code' => 'delta_canal',
                'name' => ['ar' => 'الدلتا والقناة', 'en' => 'Delta & Canal'],
                'price' => 90,
                'sort_order' => 30,
                'cities' => [
                    ['id' => 'RrDhS8YYsXAwZ9Zfo', 'name' => 'Dakahlia', 'name_ar' => 'الدقهليه'],
                    ['id' => 'yp3atroeTwnyiBNKE', 'name' => 'El Kalioubia', 'name_ar' => 'القليوبيه'],
                    ['id' => 'K3RwC677J8kJytdZD', 'name' => 'Gharbia', 'name_ar' => 'الغربيه'],
                    ['id' => 'ByP7rFCjL6XzF6j4S', 'name' => 'Kafr Alsheikh', 'name_ar' => 'كفر الشيخ'],
                    ['id' => 'ruBSjGBDX9wpRa3cc', 'name' => 'Monufia', 'name_ar' => 'المنوفيه'],
                    ['id' => '6ExcoGbpYHnggP8JD', 'name' => 'Sharqia', 'name_ar' => 'الشرقيه'],
                    ['id' => 'qoZvYcZ8Cqji4pGp5', 'name' => 'Damietta', 'name_ar' => 'دمياط'],
                    ['id' => 'PJqNriLtFtx2cfkKP', 'name' => 'Ismailia', 'name_ar' => 'الاسماعيليه'],
                    ['id' => 'skFtf6ZmKo8kBEBDK', 'name' => 'Port Said', 'name_ar' => 'بور سعيد'],
                    ['id' => 'PickurJ5uJZ9rDTHW', 'name' => 'Suez', 'name_ar' => 'السويس'],
                ],
            ],
            [
                'code' => 'north_upper_egypt',
                'name' => ['ar' => 'شمال الصعيد', 'en' => 'North Upper Egypt'],
                'price' => 105,
                'sort_order' => 40,
                'cities' => [
                    ['id' => 'BW5MiNxEirB7tuz2y', 'name' => 'Fayoum', 'name_ar' => 'الفيوم'],
                    ['id' => 'LzbbvTzZ7D2CgE2PL', 'name' => 'Bani Suif', 'name_ar' => 'بني سويف'],
                    ['id' => 'si6eLnKjXqTFTMBj9', 'name' => 'Menya', 'name_ar' => 'المنيا'],
                    ['id' => '7mDPAohM3ArSZmWTm', 'name' => 'Assuit', 'name_ar' => 'اسيوط'],
                    ['id' => 'n3EENg2adhuR9xBZK', 'name' => 'Sohag', 'name_ar' => 'سوهاج'],
                ],
            ],
            [
                'code' => 'south_upper_egypt',
                'name' => ['ar' => 'جنوب الصعيد', 'en' => 'South Upper Egypt / Remote'],
                'price' => 120,
                'sort_order' => 50,
                'cities' => [
                    ['id' => 'vfTHTes3uGjAszgtg', 'name' => 'Qena', 'name_ar' => 'قنا'],
                    ['id' => 'wgYEdH2WMzxGE2Ztp', 'name' => 'Luxor', 'name_ar' => 'الاقصر'],
                    ['id' => 'kLvZ5JY6LJPL5chzN', 'name' => 'Aswan', 'name_ar' => 'اسوان'],
                    ['id' => 'r5TscLCNSjR2GimxQ', 'name' => 'Red Sea', 'name_ar' => 'البحر الاحمر'],
                    ['id' => 'KBpGiRZJMIx', 'name' => 'Matrouh', 'name_ar' => 'مرسي مطروح'],
                ],
            ],
            [
                'code' => 'north_coast',
                'name' => ['ar' => 'الساحل الشمالي', 'en' => 'North Coast'],
                'price' => 125,
                'sort_order' => 60,
                // Official Bosta city (separate from Matrouh).
                'cities' => [
                    ['id' => '2hGtNLfRgqGrJjnW9', 'name' => 'North Coast', 'name_ar' => 'الساحل الشمالي'],
                ],
            ],
            [
                'code' => 'sinai_new_valley',
                'name' => ['ar' => 'سيناء والوادي الجديد', 'en' => 'Sinai & New Valley'],
                'price' => 145,
                'sort_order' => 70,
                'cities' => [
                    // North Sinai: NOT in Bosta cities API — intentionally unmapped.
                    ['id' => 'nG_c44vHQht', 'name' => 'South Sinai', 'name_ar' => 'جنوب سيناء'],
                    ['id' => 'w4yDVHVJWqa4HpbzA', 'name' => 'New Valley', 'name_ar' => 'الوادي الجديد'],
                ],
            ],
        ];

        foreach ($groups as $groupData) {
            $cities = $groupData['cities'];
            unset($groupData['cities']);

            $group = ShippingRateGroup::query()->updateOrCreate(
                ['code' => $groupData['code']],
                $groupData + ['is_active' => true],
            );

            foreach ($cities as $city) {
                ShippingRateLocation::query()->updateOrCreate(
                    [
                        'scope_type' => ShippingLocationScope::City->value,
                        'bosta_location_id' => $city['id'],
                    ],
                    [
                        'shipping_rate_group_id' => $group->id,
                        'bosta_location_name' => $city['name'],
                        'bosta_location_name_ar' => $city['name_ar'],
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
