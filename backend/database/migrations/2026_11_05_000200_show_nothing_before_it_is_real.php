<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Before the website goes public, two seeds stop pretending:
// - The store links were placeholders (an App Store link without the app's number; a Google Play link to a
//   package that is not ours). A store link is now empty until the app is published there, and the website
//   shows its badge only then (App\Support\Site\Site::stores()).
// - The five policies were published with one placeholder sentence each, some of them wrong (e.g. «لا عمولة بيع»).
//   Untouched placeholders go back to drafts: a policy is shown once its owner writes and saves it in the panel.
return new class extends Migration
{
    private const PLACEHOLDER_STORES = ['https://apps.apple.com/app/zon', 'https://play.google.com/store/apps/details?id=sa.zon.app'];

    private const PLACEHOLDER_POLICIES = [
        'payments' => 'الباقات تُدفع سنوياً أو شهرياً داخل المنصة. لا عمولة بيع أو تأجير على الإعلان. المبالغ غير شاملة ضريبة القيمة المضافة إن وُجدت لاحقاً.',
        'privacy' => 'زون تجمع بيانات الحساب والعقار لإتمام الوساطة والإعلان وفق نظام الهيئة العامة للعقار. لا تُشارك بيانات المالك الخاصة خارج إدارة الملاك لدى الوسيط.',
        'ip' => 'الصور والوصف ملك المعلن. زون تحتفظ بترخيص عرضها داخل المنصة. يُمنع نسخ بيانات الباحثين أو الوسطاء لأغراض خارج التطبيق.',
        'terms' => 'استخدام زون يعني الالتزام بأن بيانات العرض والطلب صحيحة، وأن المعلن مرخّص أو يصدر ترخيصاً إعلانياً عبر المنصة عند الحاجة.',
        'rega' => 'كل إعلان فردي يعرض رمز QR للتحقق لدى الهيئة العامة للعقار. مشاريع المساهمة قد تُستثنى من رقم الترخيص الإعلاني وفق النظام.',
    ];

    public function up(): void
    {
        Schema::table('app_configs', function (Blueprint $table) {
            $table->string('store_url', 500)->nullable()->change();
        });
        DB::table('app_configs')->whereIn('store_url', self::PLACEHOLDER_STORES)->update(['store_url' => null]);

        foreach (self::PLACEHOLDER_POLICIES as $slug => $body) {
            DB::table('legal_pages')->where('slug', $slug)->where('version', '1')->where('body', $body)->update(['published_at' => null]);
        }
    }

    public function down(): void
    {
        DB::table('app_configs')->whereNull('store_url')->update(['store_url' => '']);
        Schema::table('app_configs', function (Blueprint $table) {
            $table->string('store_url', 500)->nullable(false)->change();
        });
    }
};
