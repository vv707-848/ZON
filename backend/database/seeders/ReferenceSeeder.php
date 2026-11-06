<?php

namespace Database\Seeders;

use App\Enums\Region;
use App\Models\AppConfig;
use App\Models\City;
use App\Models\LegalPage;
use App\Models\SearchTerm;
use Illuminate\Database\Seeder;

/** Cities, versions, policies and local terms — first values from app 3.1.2, then edited from the panel. */
class ReferenceSeeder extends Seeder
{
    /**
     * The first allowed and latest app version: the one the app reports (app/pubspec.yaml `version`, the store's
     * version), not the name of the delivery (3.1.2). The panel raises it from then on; AppVersionContractTest
     * keeps the app in this repository above it.
     */
    public const FIRST_APP_VERSION = '1.2.1';

    /** Each city's administrative region (the market map; not in the app). */
    private const REGIONS = ['الرياض' => Region::Riyadh, 'جدة' => Region::Makkah, 'الدمام' => Region::Eastern, 'الخبر' => Region::Eastern];

    /** The app's kCities (lib/data/catalog/cities.dart). */
    private const CITIES = [
        'الرياض' => ['النرجس', 'الملقا', 'العليا', 'الياسمين', 'حطين', 'القيروان', 'العارض', 'الورود'],
        'جدة' => ['الشاطئ', 'أبحر الشمالية', 'الروضة', 'الحمراء', 'الزهراء'],
        'الدمام' => ['الفيصلية', 'الشاطئ', 'النور', 'الجلوية'],
        'الخبر' => ['العقربية', 'اليرموك', 'الحزام', 'الكورنيش'],
    ];

    /** The app's policy texts (lib/features/legal/legal_screen.dart), version 1. */
    /** The five policies of the app's settings: titles, and a first line to replace (drafts, never published as is). */
    private const LEGAL = [
        'payments' => ['سياسة الدفع', 'الباقات تُدفع سنوياً أو شهرياً داخل المنصة. لا عمولة بيع أو تأجير على الإعلان. المبالغ غير شاملة ضريبة القيمة المضافة إن وُجدت لاحقاً.'],
        'privacy' => ['سياسة الخصوصية', 'زون تجمع بيانات الحساب والعقار لإتمام الوساطة والإعلان وفق نظام الهيئة العامة للعقار. لا تُشارك بيانات المالك الخاصة خارج إدارة الملاك لدى الوسيط.'],
        'ip' => ['سياسة حقوق الملكية والفكرية', 'الصور والوصف ملك المعلن. زون تحتفظ بترخيص عرضها داخل المنصة. يُمنع نسخ بيانات الباحثين أو الوسطاء لأغراض خارج التطبيق.'],
        'terms' => ['أحكام وشروط استخدام المنصة', 'استخدام زون يعني الالتزام بأن بيانات العرض والطلب صحيحة، وأن المعلن مرخّص أو يصدر ترخيصاً إعلانياً عبر المنصة عند الحاجة.'],
        'rega' => ['ضوابط الإعلانات العقارية', 'كل إعلان فردي يعرض رمز QR للتحقق لدى الهيئة العامة للعقار. مشاريع المساهمة قد تُستثنى من رقم الترخيص الإعلاني وفق النظام.'],
    ];

    /** Local words and what people mean by them (architecture §9). */
    private const TERMS = [
        'حوش' => ['فناء', 'حديقة داخلية', 'ساحة'],
        'ملحق' => ['غرفة سطح', 'ملحق علوي'],
        'مجلس' => ['مجلس رجال', 'صالة استقبال'],
        'دبلكس' => ['فيلا دورين', 'دوبلكس'],
        'شقة تمليك' => ['شقة للبيع', 'تمليك'],
        'عمارة' => ['مبنى سكني', 'بناية'],
        'استراحة' => ['شاليه', 'مزرعة صغيرة'],
        'غرفة سائق' => ['سكن سائق', 'غرفة خادمة'],
    ];

    public function run(): void
    {
        $sort = 0;
        foreach (self::CITIES as $name => $neighborhoods) {
            $city = City::query()->firstOrCreate(['name' => $name], ['sort' => $sort++, 'region' => self::REGIONS[$name]]);
            foreach ($neighborhoods as $neighborhood) {
                $city->neighborhoods()->firstOrCreate(['name' => $neighborhood]);
            }
        }

        // The store link is set in «إعدادات التطبيق» once the app is published there (the website shows its badge then).
        foreach (['ios', 'android'] as $platform) {
            AppConfig::query()->firstOrCreate(['platform' => $platform], ['min_version' => self::FIRST_APP_VERSION, 'latest_version' => self::FIRST_APP_VERSION, 'store_url' => null]);
        }

        // Drafts: a policy is shown in the app and on the website once its owner writes and saves it in the panel.
        foreach (self::LEGAL as $slug => [$title, $body]) {
            LegalPage::query()->firstOrCreate(['slug' => $slug], ['title' => $title, 'body' => $body, 'version' => '1', 'published_at' => null]);
        }

        foreach (self::TERMS as $term => $synonyms) {
            SearchTerm::query()->firstOrCreate(['term' => $term], ['synonyms' => $synonyms]);
        }
    }
}
