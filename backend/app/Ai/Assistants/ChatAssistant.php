<?php

namespace App\Ai\Assistants;

use App\Actions\Billing\ApplyBoost;
use App\Ai\AiGateway;
use App\Ai\AiResult;
use App\Ai\Capture\TrainingCapture;
use App\Ai\Masking\AiMasking;
use App\Enums\AiAgent;
use App\Enums\BoostKind;
use App\Enums\ChatIntent;
use App\Models\ChatMessage;
use App\Models\Customer;
use App\Support\ArabicText;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use App\Support\Trace;

/**
 * «زون» — the chat (barq_chat_screen.dart), in this order:
 *   1 blocked words → a fixed answer · 2 language (ar · en · ur · xx) · 3 intent by rules; out of scope → a fixed
 *   answer, no call — in any other language (xx) the rules are skipped, but for the plans' own words, and the model
 *   names the intent («auto»; «out of scope» is its own sentence in that language) · 4 a plan question → the pointer
 *   to the plans page (PLANS_GUIDANCE) and its button, no call: no plan price reaches a model (owner, 21 Nov 2026) ·
 *   5 the call with a closed context (knowledge passages) · 6 ChatGuard; a plan answer — named by the model, refused,
 *   or with the AI down — is the pointer too · 7 saved (masked) in chat_messages.
 * The account's own name is masked with the rest («أنا خالد…» reaches no model, no row, no training sample).
 * The answer is in the message's language; a fixed text is in ar · en · ur, and in English for any other.
 */
final class ChatAssistant
{
    private const FIXED = [
        'blocked' => ['ar' => 'لا أستطيع المساعدة في هذا. اسألني عن العقار والباقات والخدمات في زون.', 'en' => 'I can’t help with that. Ask me about property, plans and Zon’s services.', 'xx' => 'I can’t help with that. Ask me about property, plans and Zon’s services.', 'ur' => 'میں اس میں مدد نہیں کر سکتا۔ جائیداد اور زون کی خدمات کے بارے میں پوچھیں۔'],
        'greeting' => ['ar' => 'هلا وغلا، يالله حيه! أنا مساعد زون العقاري: أساعدك في البحث عن عقار، والمطابقة، والباقات، والتراخيص، والنشر. اكتب لي وش تحتاج وأبشر.', 'en' => 'Hello and welcome! I’m Zon’s property assistant: search, matching, plans, licences and publishing — tell me what you need.', 'xx' => 'Hello and welcome! I’m Zon’s property assistant: search, matching, plans, licences and publishing — tell me what you need.', 'ur' => 'خوش آمدید! میں زون کا پراپرٹی اسسٹنٹ ہوں: تلاش، پیکجز، لائسنس اور اشاعت میں مدد کرتا ہوں۔'],
        'out_of_scope' => ['ar' => 'أنا مساعد زون العقاري: أجيب عن الباقات والتراخيص والنشر والبحث والمطابقة.', 'en' => 'I’m Zon’s property assistant: plans, licences, publishing, search and matching.', 'xx' => 'I’m Zon’s property assistant: plans, licences, publishing, search and matching.', 'ur' => 'میں زون کا اسسٹنٹ ہوں: پیکجز، لائسنس، اشاعت، تلاش۔'],
        'unavailable' => ['ar' => 'تعذّر الرد الآن، حاول بعد قليل أو تواصل مع الدعم من الإعدادات.', 'en' => 'I can’t answer right now — try again shortly or contact support from Settings.', 'xx' => 'I can’t answer right now — try again shortly or contact support from Settings.', 'ur' => 'ابھی جواب ممکن نہیں، تھوڑی دیر بعد کوشش کریں۔'],
        'not_covered' => ['ar' => 'لا أملك معلومة موثقة عن هذا. راجع الجهة الرسمية المختصة.', 'en' => 'I don’t have verified information on this. Please check with the official authority.', 'xx' => 'I don’t have verified information on this. Please check with the official authority.', 'ur' => 'اس بارے میں مصدقہ معلومات نہیں ہیں۔'],
    ];

    /**
     * The pointer to the plans page, in the asker's language (the agents' own: zon_agents/pipelines/chat.py, both
     * kept equal to docs/contracts/fixtures/chat/plans_guidance.json). The page shows the prices as they are today.
     */
    public const PLANS_GUIDANCE = [
        'ar' => 'أسعار الباقات وتفاصيلها في صفحة «الباقات»: اضغط الزر تحت هذه الرسالة وتشوفها محدّثة.',
        'en' => 'Plan prices and details are on the Plans page: tap the button below this message to see them up to date.',
        'ur' => 'پیکجز کی قیمتیں اور تفصیل «پیکجز» کے صفحے پر ہیں: تازہ ترین دیکھنے کے لیے اس پیغام کے نیچے بٹن دبائیں۔',
        'tr' => 'Paketlerin fiyatları ve ayrıntıları «Paketler» sayfasında: güncel hâlini görmek için bu mesajın altındaki düğmeye dokunun.',
        'ru' => 'Цены и условия тарифов — на странице «Тарифы»: нажмите кнопку под этим сообщением, чтобы увидеть актуальные.',
        'hi' => 'पैकेज की कीमतें और विवरण «पैकेज» पेज पर हैं: ताज़ा जानकारी के लिए इस संदेश के नीचे दिया बटन दबाएँ।',
    ];

    /** Where the app should take the user for the services the chat only points to. */
    private const ACTIONS = [
        'semantic_search' => '/account/search',
        'search_request' => '/add',
        'matching' => '/account/matching',
        'packages' => '/account/plans',
    ];

    public function __construct(private readonly AiGateway $ai, private readonly IntentClassifier $classifier, private readonly ChatGuard $guard) {}

    /** @return array{answer: string, covered: bool, sources: list<array<string, mixed>>, intent: string, lang: string, action: ?string, ai: array<string, mixed>} */
    public function ask(Customer $customer, string $message): array
    {
        $lang = IntentClassifier::language($message);
        if (ArabicText::containsAny($message, Settings::list(Setting::ChatBlockedTerms))) {
            return $this->answer($customer, $message, $lang, ChatIntent::OutOfScope, self::FIXED['blocked'][$lang], false, [], 'replaced', null);
        }
        $auto = $lang === 'xx';
        $intent = $auto ? null : $this->classifier->classify($message);
        if ($intent === ChatIntent::OutOfScope) {       // a greeting alone is welcomed, warmly, and still «out of scope»
            $fixed = IntentClassifier::isGreeting($message) ? 'greeting' : 'out_of_scope';

            return $this->answer($customer, $message, $lang, $intent, self::FIXED[$fixed][$lang], false, [], 'passed', null);
        }

        if ($intent === ChatIntent::Packages || ($auto && IntentClassifier::asksPlans($message))) {
            return $this->plans($customer, $message, $lang, 'passed', null);
        }

        $amounts = $this->amounts();
        $last = ChatMessage::query()->where('customer_id', $customer->id)->latest('id')->first();
        $result = $this->ai->call(AiAgent::Chat, [
            'lang' => $lang,
            'role' => $customer->role->value,      // the services he has: a seeker searches and matches, a broker searches
            'intent' => $intent->value ?? 'auto',
            'message' => $message,
            'history' => $last === null ? [] : [['q' => mb_substr($last->question, 0, 1000), 'a' => mb_substr($last->answer, 0, 2000)]],
        ], check: fn (array $out) => trim((string) ($out['answer'] ?? '')) === '' ? null : [
            'answer' => trim((string) $out['answer']),
            'covered' => (bool) ($out['covered'] ?? false),
            'sources' => array_values(array_filter((array) ($out['sources'] ?? []), 'is_array')),
            'intent' => (string) ($out['intent'] ?? ''),
        ], customer: $customer, cache: false, known: [$customer->name]);

        if (! $result->usable()) {
            return $this->answer($customer, $message, $lang, $intent ?? ChatIntent::OutOfScope, self::FIXED['unavailable'][$lang], false, [], 'passed', $result);
        }

        $out = $result->output;
        $intent ??= ChatIntent::tryFrom($out['intent']) ?? ChatIntent::OutOfScope;    // named by the model («auto»)
        // a plan price under another intent («الباقة بـ199 ليرة تركية»، «199 TL»): the pointer too; a documented
        // service fee in its own sentence stays (PlanPrice)
        if ($intent !== ChatIntent::Packages && PlanPrice::leaks($out['answer'], $amounts)) {
            return $this->plans($customer, $message, $lang, 'replaced', $result);
        }
        if ($intent === ChatIntent::Packages) {             // whatever the model wrote («199 ليرة تركية»): the pointer
            return $this->plans($customer, $message, $lang, $out['answer'] === self::PLANS_GUIDANCE[self::plansLang($lang, $message)] ? 'passed' : 'replaced', $result);
        }
        if ($intent === ChatIntent::OutOfScope) {           // its one sentence, in his language, if nothing in it is unsafe
            $said = $this->guard->passes($out['answer'], false, [], $amounts, false);

            return $this->answer($customer, $message, $lang, $intent, $said ? $out['answer'] : self::FIXED['out_of_scope'][$lang], false, [], $said ? 'passed' : 'replaced', $result);
        }
        $ok = $this->guard->passes($out['answer'], $out['covered'], $out['sources'], $amounts, $intent === ChatIntent::Knowledge);
        if (! $ok) {
            return $this->answer($customer, $message, $lang, $intent, self::FIXED['not_covered'][$lang], false, [], 'replaced', $result);
        }

        return $this->answer($customer, $message, $lang, $intent, $out['answer'], $out['covered'], $out['sources'], 'passed', $result);
    }

    /**
     * The pointer to the plans page, with its button (ACTIONS['packages']): no price said, the page shows today's.
     *
     * @return array{answer: string, covered: bool, sources: list<array<string, mixed>>, intent: string, lang: string, action: ?string, ai: array<string, mixed>}
     */
    private function plans(Customer $customer, string $message, string $lang, string $guard, ?AiResult $result): array
    {
        return $this->answer($customer, $message, $lang, ChatIntent::Packages, self::PLANS_GUIDANCE[self::plansLang($lang, $message)], true, [], $guard, $result);
    }

    /** The pointer's language: the rules' (ar · en · ur), or another one's by its script, Turkish by its own letters or words, else English (agents: chat.guidance_lang). */
    public static function plansLang(string $lang, string $message): string
    {
        if ($lang !== 'xx') {
            return $lang;
        }
        $lower = mb_strtolower($message);

        return match (true) {
            preg_match('/\p{Cyrillic}/u', $message) === 1 => 'ru',
            preg_match('/\p{Devanagari}/u', $message) === 1 => 'hi',
            preg_match('/[ğışİĞŞ]|\b(?:paket\w*|fiyat\w*|ücret\w*|aylık|yıllık|kadar|nedir|nasıl|için|abonelik|üyelik)\b/u', $lower) === 1 => 'tr',
            default => 'en',
        };
    }

    /**
     * Every amount the chat may say: the documented fees and shares of the platform's services — the ad licence,
     * the commission and the broker's share, and the boosts' («عاجل»، «تمييز») — and no plan's price (owner, 21 Nov
     * 2026: plan prices are the plans page's alone; an answer that says one is refused).
     *
     * @return list<string>
     */
    private function amounts(): array
    {
        $amounts = [Settings::money(Setting::AdLicenceFee)->riyals(), Settings::string(Setting::OwnerCommissionPercent), Settings::string(Setting::BrokerFreeSharePercent)];
        foreach (BoostKind::cases() as $kind) {
            $amounts[] = ApplyBoost::price($kind)->riyals();
        }

        return array_values(array_unique($amounts));
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return array{answer: string, covered: bool, sources: list<array<string, mixed>>, intent: string, lang: string, action: ?string, ai: array<string, mixed>}
     */
    private function answer(Customer $customer, string $message, string $lang, ChatIntent $intent, string $answer, bool $covered, array $sources, string $guard, ?AiResult $result): array
    {
        $masked = AiMasking::text($message, [$customer->name]);
        ChatMessage::query()->create([
            'customer_id' => $customer->id,
            'lang' => $lang,
            'intent' => $intent,
            'question' => $masked,
            'answer' => $answer,
            'kb_doc_ids' => array_values(array_filter(array_map(fn (array $s) => isset($s['doc_id']) ? (int) $s['doc_id'] : null, $sources))),
            'guard_status' => $guard,
            'ai_status' => $result?->status->value ?? 'skipped',
            'trace_id' => Trace::id(),
            'created_at' => now(),
        ]);
        TrainingCapture::note('chat', $masked, $intent->value, $customer);

        return [
            'answer' => $answer,
            'covered' => $covered,
            'sources' => array_map(fn (array $s) => array_intersect_key($s, array_flip(['doc_id', 'title', 'effective_date', 'quote_ar'])), $sources),
            'intent' => $intent->value,
            'lang' => $lang,
            'action' => self::ACTIONS[$intent->value] ?? null,
            'ai' => $result?->meta() ?? AiResult::skipped(),
        ];
    }
}
