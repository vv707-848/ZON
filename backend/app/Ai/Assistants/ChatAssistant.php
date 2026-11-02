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
use App\Models\Package;
use App\Support\ArabicText;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use App\Support\Trace;

/**
 * «زون» — the chat (barq_chat_screen.dart), in this order:
 *   1 blocked words → a fixed answer · 2 language (ar · en · ur) · 3 intent by rules; out of scope → a fixed
 *   answer, no call · 4 the call with a closed context (knowledge passages, or the packages' exact numbers) ·
 *   5 ChatGuard · 6 packages answered from the table itself when the AI is down · 7 saved (masked) in chat_messages.
 */
final class ChatAssistant
{
    private const FIXED = [
        'blocked' => ['ar' => 'لا أستطيع المساعدة في هذا. اسألني عن العقار والباقات والخدمات في زون.', 'en' => 'I can’t help with that. Ask me about property, plans and Zon’s services.', 'ur' => 'میں اس میں مدد نہیں کر سکتا۔ جائیداد اور زون کی خدمات کے بارے میں پوچھیں۔'],
        'out_of_scope' => ['ar' => 'أنا مساعد زون العقاري: أجيب عن الباقات والتراخيص والنشر والبحث والمطابقة.', 'en' => 'I’m Zon’s property assistant: plans, licences, publishing, search and matching.', 'ur' => 'میں زون کا اسسٹنٹ ہوں: پیکجز، لائسنس، اشاعت، تلاش۔'],
        'unavailable' => ['ar' => 'تعذّر الرد الآن، حاول بعد قليل أو تواصل مع الدعم من الإعدادات.', 'en' => 'I can’t answer right now — try again shortly or contact support from Settings.', 'ur' => 'ابھی جواب ممکن نہیں، تھوڑی دیر بعد کوشش کریں۔'],
        'not_covered' => ['ar' => 'لا أملك معلومة موثقة عن هذا. راجع الجهة الرسمية المختصة.', 'en' => 'I don’t have verified information on this. Please check with the official authority.', 'ur' => 'اس بارے میں مصدقہ معلومات نہیں ہیں۔'],
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
        $intent = $this->classifier->classify($message);
        if ($intent === ChatIntent::OutOfScope) {
            return $this->answer($customer, $message, $lang, $intent, self::FIXED['out_of_scope'][$lang], false, [], 'passed', null);
        }

        [$packages, $amounts] = $this->packageFacts();
        $last = ChatMessage::query()->where('customer_id', $customer->id)->latest('id')->first();
        $result = $this->ai->call(AiAgent::Chat, [
            'lang' => $lang,
            'intent' => $intent->value,
            'message' => $message,
            'history' => $last === null ? [] : [['q' => mb_substr($last->question, 0, 1000), 'a' => mb_substr($last->answer, 0, 2000)]],
            'packages' => $intent === ChatIntent::Packages ? $packages : [],
        ], check: fn (array $out) => trim((string) ($out['answer'] ?? '')) === '' ? null : [
            'answer' => trim((string) $out['answer']),
            'covered' => (bool) ($out['covered'] ?? false),
            'sources' => array_values(array_filter((array) ($out['sources'] ?? []), 'is_array')),
        ], customer: $customer, cache: false);

        if (! $result->usable()) {
            $fallback = $intent === ChatIntent::Packages ? $this->packagesText($packages, $lang) : self::FIXED['unavailable'][$lang];

            return $this->answer($customer, $message, $lang, $intent, $fallback, $intent === ChatIntent::Packages, [], 'passed', $result);
        }

        $out = $result->output;
        $ok = $this->guard->passes($out['answer'], $out['covered'], $out['sources'], $amounts, $intent === ChatIntent::Knowledge);
        if (! $ok) {
            $text = $intent === ChatIntent::Packages ? $this->packagesText($packages, $lang) : self::FIXED['not_covered'][$lang];

            return $this->answer($customer, $message, $lang, $intent, $text, false, [], 'replaced', $result);
        }

        return $this->answer($customer, $message, $lang, $intent, $out['answer'], $out['covered'], $out['sources'], 'passed', $result);
    }

    /** @return array{0: list<array<string, mixed>>, 1: list<string>} the packages as facts, and every amount the chat may say */
    private function packageFacts(): array
    {
        $plans = Package::query()->where('is_active', true)->where('price_halalas', '>', 0)->orderBy('sort')->get();
        $facts = $plans->map(fn (Package $p) => ['name' => $p->name, 'price' => (float) $p->price()->riyals(), 'period' => $p->period->value, 'features' => $p->features ?? []])->all();
        $amounts = $plans->map(fn (Package $p) => $p->price()->riyals())->all();
        foreach (BoostKind::cases() as $kind) {
            $amounts[] = ApplyBoost::price($kind)->riyals();
        }
        array_push($amounts, Settings::money(Setting::AdLicenceFee)->riyals(), Settings::string(Setting::OwnerCommissionPercent), Settings::string(Setting::BrokerFreeSharePercent), '1060');

        return [$facts, array_values(array_unique($amounts))];
    }

    /** @param list<array<string, mixed>> $packages */
    private function packagesText(array $packages, string $lang): string
    {
        $lines = array_map(fn (array $p) => "• {$p['name']}: ".rtrim(rtrim(number_format((float) $p['price'], 2, '.', ''), '0'), '.').' ريال', $packages);

        return ($lang === 'ar' ? "الباقات الحالية في زون:\n" : "Zon plans:\n").implode("\n", $lines);
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return array{answer: string, covered: bool, sources: list<array<string, mixed>>, intent: string, lang: string, action: ?string, ai: array<string, mixed>}
     */
    private function answer(Customer $customer, string $message, string $lang, ChatIntent $intent, string $answer, bool $covered, array $sources, string $guard, ?AiResult $result): array
    {
        $masked = AiMasking::text($message);
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
        TrainingCapture::note('chat', $masked, $intent->value);

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
