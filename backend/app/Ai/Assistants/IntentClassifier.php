<?php

namespace App\Ai\Assistants;

use App\Enums\ChatIntent;
use App\Support\ArabicText;

/**
 * The chat's intent by rules, in priority order: packages > search_request > matching > knowledge >
 * semantic_search. Nothing matches → out of scope (a fixed answer, no AI call).
 */
final class IntentClassifier
{
    /** @var array<string, list<string>> intent => phrases (Arabic, English, Urdu) */
    private const RULES = [
        'packages' => ['باقة', 'باقات', 'الباقه', 'اشتراك', 'الاشتراك', 'رصيد', 'تمييز', 'مميز', 'سعر الباقة', 'كم السعر', 'كم سعر', 'تكلفة', 'package', 'subscription', 'plan', 'pricing', 'پیکج', 'قیمت'],
        'search_request' => ['طلب بحث', 'اضيف طلب', 'أضيف طلب', 'انشر طلب', 'طلب عاجل', 'اسوي طلب', 'post a request', 'add a request', 'درخواست'],
        'matching' => ['مطابقة', 'رغبتي', 'رغبة', 'يطابق', 'المطابق', 'match', 'matching', 'my wish'],
        'knowledge' => ['ترخيص', 'رخصة', 'فال', 'نفاذ', 'الهيئة', 'عقد', 'سعي', 'عمولة', 'ضريبة', 'صك', 'تملك', 'نظام', 'شروط', 'سياسة', 'وساطة', 'تسجيل عيني', 'إفراغ', 'افراغ',
            'licence', 'license', 'contract', 'commission', 'tax', 'policy', 'deed', 'لائسنس'],
        'semantic_search' => ['ابحث', 'أبحث', 'ابغى', 'ابي', 'أبي', 'ودي', 'شقة', 'شقه', 'فيلا', 'فله', 'ارض', 'أرض', 'عمارة', 'دور', 'استراحة', 'محل', 'مكتب', 'للبيع', 'للايجار', 'للإيجار',
            'search', 'looking for', 'apartment', 'villa', 'land', 'rent', 'buy', 'فلیٹ', 'گھر', 'کرایہ'],
    ];

    public function classify(string $message): ChatIntent
    {
        foreach (self::RULES as $intent => $phrases) {
            if (ArabicText::containsAny($message, $phrases)) {
                return ChatIntent::from($intent);
            }
        }

        return ChatIntent::OutOfScope;
    }

    /** ar · en · ur — Urdu has letters Arabic lacks; Latin letters mean English. */
    public static function language(string $message): string
    {
        return match (true) {
            preg_match('/[ٹڈڑںہھےۓکگ]/u', $message) === 1 => 'ur',
            preg_match('/\p{Arabic}/u', $message) === 1 => 'ar',
            preg_match('/[A-Za-z]/', $message) === 1 => 'en',
            default => 'ar',
        };
    }
}
