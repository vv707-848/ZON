<?php

namespace App\SuperAgent;

use App\Support\ArabicText;

/**
 * Every number in the model's analysis must come from the rows (or the parameters) of the reports it read: a
 * number the model made up rejects the analysis — the rows are still shown, without it.
 */
final class NumberGuard
{
    /**
     * @param  list<array<string, mixed>>  $rows  of every report the analysis covers
     * @param  array<mixed>  $params  the values of their parameters
     */
    public function passes(string $text, array $rows, array $params): bool
    {
        $allowed = [];
        foreach ($rows as $row) {
            foreach ($row as $value) {
                if (is_numeric($value)) {
                    foreach ($this->forms((float) $value) as $form) {
                        $allowed[$form] = true;
                    }
                }
            }
        }
        foreach ($params as $value) {
            if (is_numeric($value)) {
                $allowed[$this->plain((string) $value)] = true;
            }
        }

        preg_match_all('/\d[\d,٬]*(?:\.\d+)?/u', ArabicText::asciiDigits($text), $matches);
        foreach ($matches[0] as $number) {
            $plain = $this->plain($number);
            if ((float) $plain <= 12 && ! str_contains($plain, '.')) {
                continue;                                  // small counts and ordinals («الثاني», «3 أحياء»)
            }
            if (! isset($allowed[$plain])) {
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
