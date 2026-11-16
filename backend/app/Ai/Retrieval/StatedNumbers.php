<?php

namespace App\Ai\Retrieval;

use App\Support\ArabicText;

/**
 * The price, the rooms and the area a free text states in so many words («شقة 4 غرف بحدود 900 ألف، مساحة 180»):
 * the search form asks for a description only, and the conditions the ranking checks in numbers (the agents'
 * pipelines/ranking.py) need them. Read only when said plainly and once — two different prices outside a range,
 * two room counts, a bare «16 متر» (a street's width) give nothing, and the model reads the text as before.
 */
final class StatedNumbers
{
    private const NUMBER = '(\d{1,3}(?:[,٬]\d{3})+|\d+(?:\.\d+)?)';

    private const ROOMS = ['غرفتين' => 2, 'غرفتان' => 2, 'ثلاث' => 3, 'ثلاثه' => 3, 'اربع' => 4, 'اربعه' => 4, 'خمس' => 5, 'خمسه' => 5, 'ست' => 6, 'سته' => 6, 'سبع' => 7, 'سبعه' => 7];

    /** @return array{price: ?float, bedrooms: ?int, area: ?float} */
    public static function in(string $text): array
    {
        $text = strtr(ArabicText::asciiDigits($text), ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', '٫' => '.']);

        return ['price' => self::price($text), 'bedrooms' => self::bedrooms($text), 'area' => self::area($text)];
    }

    /** One amount; or the top of a range said as one («500 الف الى 700 الف», «بين 1.2 مليون و1.5 مليون»). */
    private static function price(string $text): ?float
    {
        if (preg_match('/(?<![\p{L}])(?:ال)?(?:مقدم|دفعه|راتب|قسط|اقساط|عربون)/u', $text) === 1) {
            return null;   // an amount that may be a down payment, a salary or an instalment is not a price
        }
        $amounts = [];
        $keep = function (float $amount) use (&$amounts): string {
            $amounts[] = $amount;

            return '§';
        };
        $half = '(\s+ونصف?(?![\p{L}]))?';
        $rest = (string) preg_replace_callback('/'.self::NUMBER.'\s*(الف|الاف|مليون|ملايين)'.$half.'/u',
            fn (array $m) => $keep((self::number($m[1]) + (isset($m[3]) ? 0.5 : 0)) * (str_starts_with($m[2], 'ال') ? 1_000 : 1_000_000)), $text);
        $rest = (string) preg_replace_callback('/(?<![\p{L}\d])(مليونين|مليون)'.$half.'/u',
            fn (array $m) => $keep(($m[1] === 'مليونين' ? 2 : 1 + (isset($m[2]) ? 0.5 : 0)) * 1_000_000), $rest);
        // a plain number is a price only from ten thousand up: below it is a count, and a phone number starts with 0 or +
        $rest = (string) preg_replace_callback('/(?<![\d.,٬+])'.self::NUMBER.'(?![\d,٬])(?!\s*(?:م2|م²|متر))/u', function (array $m) use ($keep): string {
            $value = self::number($m[1]);

            return ! str_starts_with($m[1], '0') && $value >= 10_000 && $value < 1_000_000_000 ? $keep($value) : $m[0];
        }, $rest);
        $amounts = array_values(array_unique($amounts));

        return match (true) {
            count($amounts) === 1 => $amounts[0],
            count($amounts) === 2 && preg_match('/§\s*(?:الى|حتى|و|-|–)\s*§/u', $rest) === 1 => max($amounts),
            default => null,
        };
    }

    private static function bedrooms(string $text): ?int
    {
        $found = [];
        preg_match_all('/(?<![\d.,])(\d{1,2})\s*غرف/u', $text, $digits);
        foreach ($digits[1] as $count) {
            $found[] = (int) $count;
        }
        $counted = implode('|', array_keys(array_filter(self::ROOMS, fn (int $n) => $n > 2)));
        preg_match_all('/(?<![\p{L}])(?:(غرفتين|غرفتان)|('.$counted.')\s+غرف)/u', $text, $words, PREG_SET_ORDER);
        foreach ($words as $word) {
            $found[] = self::ROOMS[$word[2] ?? $word[1]] ?? self::ROOMS[$word[1]];
        }
        $found = array_values(array_unique($found));

        return count($found) === 1 && $found[0] >= 1 && $found[0] <= 20 ? $found[0] : null;
    }

    /** Only an area called one («مساحة 300», «300 م²», «300 متر مربع»): «16 متر» is as often a street. */
    private static function area(string $text): ?float
    {
        preg_match_all('/مساح[هت]\w*\s*(?:الارض|البناء|المباني)?\s*'.self::NUMBER.'|'.self::NUMBER.'\s*(?:م2|م²|متر\s+مربع)/u', $text, $m);
        $found = array_values(array_unique(array_map(self::number(...), array_filter([...$m[1], ...$m[2]], fn (string $n) => $n !== ''))));

        return count($found) === 1 && $found[0] >= 20 && $found[0] <= 1_000_000 ? $found[0] : null;
    }

    private static function number(string $written): float
    {
        return (float) str_replace([',', '٬'], '', $written);
    }
}
