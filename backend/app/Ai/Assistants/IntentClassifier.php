<?php

namespace App\Ai\Assistants;

use App\Enums\ChatIntent;
use App\Support\ArabicText;

/**
 * The chat's intent by rules, in priority order: packages > search_request > matching > decision > knowledge >
 * semantic_search. «decision» is a price to judge or a sale to decide: the chat gives the options, not a verdict. Nothing matches → out of scope (a fixed answer, no AI call).
 */
final class IntentClassifier
{
    /** @var array<string, list<string>> intent => phrases (Arabic, English, Urdu) */
    private const RULES = [
        // plan words only: «كم سعر المتر في الحوية» is no plan question (owner, 21 Nov 2026)
        'packages' => ['باقة', 'باقات', 'الباقه', 'باقتي', 'باقتك', 'اشتراك', 'الاشتراك', 'رصيد', 'تمييز', 'مميز', 'سعر الباقة', 'package', 'subscription', 'plan', 'پیکج'],
        'search_request' => ['طلب بحث', 'اضيف طلب', 'أضيف طلب', 'انشر طلب', 'طلب عاجل', 'اسوي طلب', 'post a request', 'add a request', 'درخواست'],
        'matching' => ['مطابقة', 'رغبتي', 'رغبة', 'يطابق', 'المطابق', 'match', 'matching', 'my wish'],
        'decision' => ['السعر زين', 'سعره زين', 'السعر مناسب', 'سعره مناسب', 'مبالغ فيه', 'سعر مبالغ', 'يستاهل السعر', 'يسوى', 'اتفاوض', 'التفاوض', 'محتار', 'متردد', 'ابيع ولا', 'اشتري ولا', 'ولا انتظر',
            'overpriced', 'fair price', 'good price', 'should i buy', 'should i sell', 'negotiate'],
        'knowledge' => ['ترخيص', 'رخصة', 'فال', 'نفاذ', 'الهيئة', 'عقد', 'سعي', 'عمولة', 'ضريبة', 'صك', 'تملك', 'نظام', 'شروط', 'سياسة', 'وساطة', 'تسجيل عيني', 'إفراغ', 'افراغ',
            'licence', 'license', 'contract', 'commission', 'tax', 'policy', 'deed', 'لائسنس'],
        'semantic_search' => ['ابحث', 'أبحث', 'ابغى', 'ابي', 'أبي', 'ودي', 'شقة', 'شقه', 'فيلا', 'فله', 'ارض', 'أرض', 'عمارة', 'دور', 'استراحة', 'محل', 'مكتب', 'للبيع', 'للايجار', 'للإيجار',
            'search', 'looking for', 'apartment', 'villa', 'land', 'rent', 'buy', 'فلیٹ', 'گھر', 'کرایہ'],
    ];

    /** The plans in the languages the rules do not read (xx): Turkish · Russian · Hindi · French, German, Spanish. */
    private const PLANS_ELSEWHERE = ['paket', 'abonelik', 'üyelik', 'тариф', 'подписк', 'пакет', 'पैकेज', 'प्लान', 'सदस्यता', 'abonnement', 'forfait', 'suscripción', 'abo '];

    /** A plan question in a language the rules do not read: answered with the plans pointer, no model (ChatAssistant). */
    public static function asksPlans(string $message): bool
    {
        $lower = mb_strtolower($message.' ');

        return array_filter(self::PLANS_ELSEWHERE, fn (string $w) => str_contains($lower, $w)) !== [];
    }

    public function classify(string $message): ChatIntent
    {
        foreach (self::RULES as $intent => $phrases) {
            if (ArabicText::containsAny($message, $phrases)) {
                return ChatIntent::from($intent);
            }
        }

        return ChatIntent::OutOfScope;
    }

    /** What a greeting is made of (ar · en · ur): a message of these words alone is welcomed, not «out of scope». */
    private const GREETING = ['السلام', 'سلام', 'عليكم', 'ورحمه', 'الله', 'وبركاته', 'هلا', 'هلا والله', 'والله', 'وغلا', 'هلابك', 'مرحبا', 'اهلا', 'اهلين', 'هاي', 'حياك', 'حيالله',
        'صباح', 'مساء', 'الخير', 'النور', 'يا', 'زون', 'hi', 'hello', 'hey', 'salam', 'good', 'morning', 'evening', 'there', 'zon', 'سلام علیکم', 'علیکم'];

    public static function isGreeting(string $message): bool
    {
        $known = array_map(ArabicText::normalize(...), self::GREETING);
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower(ArabicText::normalize($message)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $words !== [] && count($words) <= 8 && array_diff($words, $known) === [];
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
