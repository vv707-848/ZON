<?php

namespace Database\Seeders;

use App\Models\AppConfig;
use App\Models\City;
use App\Models\LegalPage;
use App\Models\SearchTerm;
use Illuminate\Database\Seeder;

/** Cities, versions, policies and local terms — first values from app 3.1.2, then edited from the panel. */
class ReferenceSeeder extends Seeder
{
    /** The app's kCities (lib/data/catalog/cities.dart). */
    private const CITIES = [
        'الرياض' => ['النرجس', 'الملقا', 'العليا', 'الياسمين', 'حطين', 'القيروان', 'العارض', 'الورود'],
        'جدة' => ['الشاطئ', 'أبحر الشمالية', 'الروضة', 'الحمراء', 'الزهراء'],
        'الدمام' => ['الفيصلية', 'الشاطئ', 'النور', 'الجلوية'],
        'الخبر' => ['العقربية', 'اليرموك', 'الحزام', 'الكورنيش'],
    ];

    /** The app's policy texts (lib/features/legal/legal_screen.dart), version 1. */
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
            $city = City::query()->firstOrCreate(['name' => $name], ['sort' => $sort++]);
            foreach ($neighborhoods as $neighborhood) {
                $city->neighborhoods()->firstOrCreate(['name' => $neighborhood]);
            }
        }

        foreach (['ios' => 'https://apps.apple.com/app/zon', 'android' => 'https://play.google.com/store/apps/details?id=sa.zon.app'] as $platform => $store) {
            AppConfig::query()->firstOrCreate(['platform' => $platform], ['min_version' => '3.1.2', 'latest_version' => '3.1.2', 'store_url' => $store]);
        }

        foreach (self::LEGAL as $slug => [$title, $body]) {
            LegalPage::query()->firstOrCreate(['slug' => $slug], ['title' => $title, 'body' => $body, 'version' => '1', 'published_at' => now()]);
        }

        foreach (self::TERMS as $term => $synonyms) {
            SearchTerm::query()->firstOrCreate(['term' => $term], ['synonyms' => $synonyms]);
        }
    }
}
