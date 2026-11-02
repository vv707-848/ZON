<?php

namespace App\Ai\Assistants;

use App\Support\ArabicText;

/**
 * The last check of a chat answer before the user sees it: no contact numbers, no links, no amount the platform
 * did not give, and «covered» needs sources. A failing answer is replaced, never edited.
 */
final class ChatGuard
{
    /**
     * @param  list<string>  $allowedAmounts  every price the platform gave (riyals, as typed: "280", "2300", "0.5")
     * @param  list<array<string, mixed>>  $sources
     */
    public function passes(string $answer, bool $covered, array $sources, array $allowedAmounts, bool $needsSources): bool
    {
        $text = ArabicText::asciiDigits($answer);
        if (preg_match('~(https?://|www\.)|\b\S+\.(com|sa|net|org)\b~iu', $text) === 1) {
            return false;
        }
        if (preg_match('~(?:\+?966|\b0)5\d{8}\b|\b05\d[\s-]?\d{3}[\s-]?\d{4}\b|\b9200\d{5}\b~', $text) === 1) {
            return false;
        }
        if ($covered && $needsSources && $sources === []) {
            return false;
        }

        $allowed = array_map(fn (string $a) => self::plain($a), $allowedAmounts);
        preg_match_all('~(\d[\d,٬.]*)\s*(?:ريال|ر\.س|SAR|riyal|ریال|٪|%)~u', $text, $matches);
        foreach ($matches[1] as $amount) {
            if (! in_array(self::plain($amount), $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    private static function plain(string $amount): string
    {
        $amount = str_replace([',', '٬'], '', $amount);

        return str_contains($amount, '.') ? rtrim(rtrim($amount, '0'), '.') : $amount;
    }
}
