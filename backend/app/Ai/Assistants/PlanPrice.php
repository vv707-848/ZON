<?php

namespace App\Ai\Assistants;

use App\Ai\Retrieval\Conditions;

/**
 * A plan's price said in a chat answer under another intent (owner, 21 Nov 2026): «الباقة بـ199 ليرة تركية»، «199 TL».
 * The chat says no plan price — the plans page does — so such an answer is the pointer to it (ChatAssistant). The
 * agents check the same (zon_agents.pipelines.chat.plan_price), against the passages they were given.
 *
 * An amount in another currency than the riyal is never ours. A number within three words of a plan word («باقة»،
 * «اشتراك»، «plan»، «paket»…) is a plan price, unless it is one of the documented service fees the chat may say in
 * their own sentence (the ad licence, the commission, a boost): «رسوم الترخيص 100 ريال» stays.
 */
final class PlanPrice
{
    private const NEAR = 3;

    private const PLAN = ['باقه', 'باقات', 'باقت', 'اشتراك', 'package', 'plan', 'subscription', 'paket', 'abonelik', 'üyelik', 'тариф', 'подписк', 'пакет', 'पैकेज', 'پیکج'];

    /** @param  list<string|int|float>  $documented  the amounts the chat may say (ChatAssistant::amounts) */
    public static function leaks(string $answer, array $documented): bool
    {
        $said = Conditions::norm($answer);
        if (preg_match('/\d[\d,.]*\s?(?:tl|₺|ليره|ليرات|lira|liras|دولار|usd|\$|€|يورو|eur)(?![\p{L}])|(?:₺|\$|€)\s?\d/u', $said) === 1) {
            return true;
        }
        $known = array_map(fn ($a) => (float) $a, $documented);
        $words = preg_split('/\s+/u', $said, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($words as $i => $word) {
            if (preg_match('/\d[\d,]*(?:\.\d+)?/u', $word, $m) !== 1 || in_array((float) str_replace(',', '', $m[0]), $known, true)) {
                continue;
            }
            foreach (array_slice($words, max(0, $i - self::NEAR), 2 * self::NEAR + 1) as $near) {
                foreach (self::PLAN as $plan) {
                    if (str_contains($near, $plan)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
