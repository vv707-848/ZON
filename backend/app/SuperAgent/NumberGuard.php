<?php

namespace App\SuperAgent;

use App\Support\ArabicText;

/**
 * Every number in the model's analysis must come from what it read — the rows, the parameters, how many rows each
 * report has: a number the model made up rejects the analysis, and the rows are still shown without it. Small
 * numbers too («الإيراد 12 مليون»): a count the data does not hold is written in words («ثلاثة أحياء»).
 *
 * A number is read from the text without its sign, so a negative value allows its magnitude («فجوة -12», «نقص 12»);
 * and a month or a date («2026-05») allows its parts («مايو 2026», «شهر 5»): both are what the rows say.
 *
 * A quantity said without digits is a number too, and none can be checked against the rows: a multiple or a share
 * («تضاعف», «ثلاثة أضعاف», «نصف الطلبات»), an amount («مليونين», «آلاف الريالات») or a percentage in words with no
 * figure before it reject the analysis. A count in words («ثلاثة أحياء») is not a quantity of the data.
 */
final class NumberGuard
{
    /** A multiple or a share, as a word of its own (after «ال» or «و»). */
    private const SHARE = '/(?<![\p{L}\d])(?:و?ب?ال)?(?:ضعف\p{L}{0,2}|اضعاف|تضاعف\p{L}{0,2}|مضاعف\p{L}{0,2}|(?:نصف|ربع|ثلث)(?:ي|ين)?)(?![\p{L}])/u';

    /** An amount or a percentage unit; one with its figure before it is removed first (that figure is checked). */
    private const UNIT = '(?:الف|الاف|الفين|مليون|ملايين|مليونين|مليار|مليارات|%|٪|بالميه|بالمايه|في الميه|في المايه)';

    /**
     * @param  list<array<string, mixed>>  $rows  of every report the analysis covers
     * @param  array<mixed>  $params  the values of their parameters, and their row counts
     */
    public function passes(string $text, array $rows, array $params): bool
    {
        $allowed = [];
        foreach ($rows as $row) {
            foreach ($row as $value) {
                if (is_numeric($value)) {
                    foreach ($this->forms(abs((float) $value)) as $form) {
                        $allowed[$form] = true;
                    }
                } elseif (is_string($value)) {
                    preg_match_all('/\d+/', ArabicText::asciiDigits($value), $parts);
                    foreach ($parts[0] as $part) {
                        $allowed[$part] = $allowed[ltrim($part, '0') ?: '0'] = true;
                    }
                }
            }
        }
        foreach ($params as $value) {
            if (is_numeric($value)) {
                $allowed[$this->plain((string) $value)] = true;
            }
        }

        $words = ArabicText::normalize($text);
        $unitless = (string) preg_replace('/\d[\d,٬.]*\s*'.self::UNIT.'/u', ' ', $words);
        if (preg_match(self::SHARE, $words) === 1 || preg_match('/(?<![\p{L}])'.self::UNIT.'(?![\p{L}])/u', $unitless) === 1) {
            return false;
        }

        preg_match_all('/\d[\d,٬]*(?:\.\d+)?/u', ArabicText::asciiDigits($text), $matches);
        foreach ($matches[0] as $number) {
            if (! isset($allowed[$this->plain($number)])) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> how a value may appear in prose: as is, rounded, in thousands / millions */
    private function forms(float $value): array
    {
        $forms = [$this->plain((string) $value), $this->plain((string) round($value)), $this->plain((string) round($value, 1)), $this->plain((string) round($value, 2))];
        if (abs($value) <= 1) {
            $forms[] = $this->plain((string) round($value * 100));            // a rate said as a percentage
            $forms[] = $this->plain((string) round($value * 100, 1));
        }
        foreach ([1000, 1_000_000] as $unit) {
            if (abs($value) >= $unit) {
                $forms[] = $this->plain((string) round($value / $unit));
                $forms[] = $this->plain((string) round($value / $unit, 1));
            }
        }

        return array_values(array_unique($forms));
    }

    private function plain(string $number): string
    {
        $number = str_replace([',', '٬'], '', $number);

        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }
}
