<?php

namespace Database\Seeders;

use App\Models\Package;
use Illuminate\Database\Seeder;

/**
 * Plans with the app's numbers (lib/data/billing/pricing.dart, kPlanQuotas). The AI caps and the free plans'
 * limits are first values to be confirmed by the app owner (OPEN_POINTS) — all editable from the panel.
 * Base plans (price 0, period none) are what an account has without a subscription.
 */
class PackageSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            // Base plans.
            ['code' => 'free-seeker', 'audience' => 'seeker', 'name' => 'الباحث — مجاني', 'period' => 'none', 'price_halalas' => 0, 'listing_limit' => 0,
                'preferences_limit' => 1, 'ai_search_daily' => 3, 'ai_matching_daily' => 3, 'ai_summary_daily' => 0, 'ai_chat_daily' => 10, 'sort' => 90],
            ['code' => 'free-broker', 'audience' => 'broker', 'name' => 'الوسيط — بلا اشتراك', 'period' => 'none', 'price_halalas' => 0,
                'ai_search_daily' => 5, 'ai_summary_daily' => 2, 'ai_chat_daily' => 10, 'inventory_limit' => 30, 'can_manage_owners' => true, 'sort' => 91,
                'features' => ['النشر متاح، وتشارك زون 10٪ من السعي عند إتمام الصفقة', 'طلبات المنطقة بلا بيانات صاحب الطلب']],
            ['code' => Package::OWNER, 'audience' => 'owner', 'name' => 'المالك', 'period' => 'none', 'price_halalas' => 0,
                'ai_search_daily' => 5, 'ai_chat_daily' => 10, 'sort' => 30,
                'features' => ['عقد وساطة عبر الهيئة وموافقة نفاذ', 'رسوم الترخيص الإعلاني 50 ريالاً', 'سعي 0.5٪ من سعر البيع بعد البيع فقط']],
            ['code' => 'developer', 'audience' => 'developer', 'name' => 'المطوّر — بعقد تسويق', 'period' => 'none', 'price_halalas' => 0,
                'ai_search_daily' => 30, 'ai_matching_daily' => 30, 'ai_summary_daily' => 20, 'ai_chat_daily' => 50, 'area_requests' => 'full',
                'can_search_requests' => true, 'preferences_limit' => 1, 'sort' => 40],

            // Plans for sale.
            ['code' => Package::BROKER_MONTH, 'audience' => 'broker', 'name' => 'الوسيط — شهري', 'period' => 'month', 'price_halalas' => 28_000,
                'featured_quota' => 1, 'ai_search_daily' => 50, 'ai_summary_daily' => 20, 'ai_chat_daily' => 50, 'area_requests' => 'full',
                'can_search_requests' => true, 'can_manage_owners' => true, 'sort' => 10,
                'features' => ['تمييز واحد في الشهر', 'طلبات المنطقة كاملة مع إضافتها للعملاء', 'البحث الذكي في الطلبات', 'بلا مشاركة في السعي']],
            ['code' => Package::BROKER_YEAR, 'audience' => 'broker', 'name' => 'الوسيط — سنوي', 'period' => 'year', 'price_halalas' => 230_000,
                'featured_quota' => 15, 'urgent_listing_quota' => 10, 'urgent_request_quota' => 5, 'ai_search_daily' => 50, 'ai_summary_daily' => 20,
                'ai_chat_daily' => 50, 'area_requests' => 'full', 'can_search_requests' => true, 'can_manage_owners' => true, 'sort' => 11,
                'features' => ['15 تمييزاً و10 «عاجل» للعروض', '5 طلبات عاجلة', 'توفير 1060 ريالاً عن الشهري', 'بلا مشاركة في السعي']],
            ['code' => Package::SEEKER, 'audience' => 'seeker', 'name' => 'الباحث — شهري', 'period' => 'month', 'price_halalas' => 19_900, 'listing_limit' => 0,
                'preferences_limit' => 5, 'ai_search_daily' => 30, 'ai_matching_daily' => 30, 'ai_chat_daily' => 50, 'sort' => 20,
                'features' => ['مساعد المطابقة يومياً', 'حتى 5 رغبات', 'البحث الدلالي']],
        ];

        foreach ($plans as $plan) {
            Package::query()->firstOrCreate(['code' => $plan['code']], $plan);
        }
    }
}
