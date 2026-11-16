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

    /** Words English uses in almost any sentence and the other Latin-script languages do not. */
    private const ENGLISH = ['the', 'a', 'an', 'is', 'are', 'i', 'my', 'me', 'you', 'your', 'what', 'how', 'can', 'do', 'does', 'for', 'to', 'in', 'of', 'with', 'and', 'about', 'need', 'want', 'much', 'there', 'this', 'which', 'where', 'when', 'why', 'it'];

    /**
     * The language the rules read and the fixed texts are written in: ar · ur (letters Arabic lacks) · en (Latin
     * letters and an English word) — or xx, any other language: its message skips the rules (they are Arabic,
     * English and Urdu phrases) and the model names the intent (ChatAssistant). The answer is in the message's own
     * language whichever this is.
     */
    public static function language(string $message): string
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($message), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return match (true) {
            preg_match('/[ٹڈڑںہھےۓکگ]/u', $message) === 1 => 'ur',
            preg_match('/\p{Arabic}/u', $message) === 1 => 'ar',
            preg_match('/^[\p{Latin}\s\p{P}\p{N}\p{S}]+$/u', $message) === 1 && array_intersect($words, self::ENGLISH) !== [] => 'en',
            preg_match('/\p{L}/u', $message) === 1 => 'xx',
            default => 'ar',
        };
    }
}
