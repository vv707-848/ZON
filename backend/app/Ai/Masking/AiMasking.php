<?php

namespace App\Ai\Masking;

use App\Support\ArabicText;

/**
 * De-identification before anything leaves for the model (§13): phone numbers, long digit runs (national ids,
 * accounts), e-mails, links, IBANs — and the names the caller knows (a client's name, and each part of it) become
 * «العميل».
 * Applied to every string of a payload, so a new field cannot slip through unmasked.
 */
final class AiMasking
{
    /**
     * Between the digits of a phone number people write spaces, dashes, dots and brackets: «055.123.4567»,
     * «(055) 123 4567», «+966 55 123 4567». Up to two of them between two digits.
     */
    private const SEP = '[\s.\-()]{0,2}';

    private const PATTERNS = [
        '~\b(?:https?://|www\.)\S+~iu' => '[رابط]',
        '~[\w.+-]+@[\w-]+\.[\w.-]+~u' => '[بريد]',
        '~\bSA\d{2}(?:[\s-]?\d){18,24}~i' => '[حساب]',
        '~(?:\+|00)\s?\(?966\)?'.self::SEP.'\d(?:'.self::SEP.'\d){7,8}~' => '[رقم]',          // +966 / 00966
        '~(?<!\d)\(?966\)?'.self::SEP.'5(?:'.self::SEP.'\d){8}(?!\d)~' => '[رقم]',               // 9665XXXXXXXX
        '~(?<!\d)\(?0\d(?:'.self::SEP.'\d){7,8}(?!\d)~' => '[رقم]',                            // 05XXXXXXXX · 01XXXXXXX
        '~(?<!\d)5\d(?:'.self::SEP.'\d){7}(?!\d)~' => '[رقم]',                                 // 5XXXXXXXX
        '~(?<!\d)(?:9200|800)(?:'.self::SEP.'\d){5,6}(?!\d)~' => '[رقم]',                       // 9200 · 800 lines
        '~\d{9,}~' => '[رقم]',                                                                   // ids, accounts
    ];

    /** A letter of a word (not a diacritic): a name is masked as a word, never inside one («علي» in «عليه»). */
    private const LETTER = '[\\x{0621}-\\x{063A}\\x{0641}-\\x{064A}\\x{066E}-\\x{06D3}A-Za-z]';

    /** Parts many names share; masked alone they would hide ordinary words («إن شاء الله»). */
    private const PARTICLES = ['عبد', 'الله', 'ابن', 'بنت', 'أبو', 'ابو', 'أم'];

    /**
     * @param  list<string>  $known  names to hide: each whole, and each part people write alone — «سلمان» or
     *                               «العتيبي» of «سلمان العتيبي» — with the letters Arabic joins to a word's front
     *                               (و ف ب ل ك: «بسلمان» → «بالعميل»)
     */
    public static function text(string $text, array $known = []): string
    {
        $text = ArabicText::asciiDigits($text);
        foreach (self::names($known) as $name) {
            $text = (string) preg_replace('~(?<!'.self::LETTER.')([وفبلك]{0,2})'.preg_quote($name, '~').'(?!'.self::LETTER.')~iu', '$1العميل', $text);
        }

        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text);
    }

    /**
     * The whole names, then their parts of three letters or more, longest first (a whole name becomes one «العميل»).
     *
     * @param  list<string>  $known
     * @return list<string>
     */
    private static function names(array $known): array
    {
        $names = [];
        foreach ($known as $name) {
            $name = trim((string) preg_replace('/\\s+/u', ' ', $name));
            if (mb_strlen($name) < 2) {
                continue;
            }
            $names[] = $name;
            // «عبد الله» and «عبدالله» are the same name, written both ways
            $joined = (string) preg_replace('/(^| )عبد (\\S)/u', '$1عبد$2', $name);
            foreach (explode(' ', $joined) as $part) {
                if (mb_strlen($part) >= 3 && ! in_array($part, self::PARTICLES, true)) {
                    $names[] = $part;
                    if (str_starts_with($part, 'عبد') && mb_strlen($part) > 3) {
                        $names[] = 'عبد '.mb_substr($part, 3);
                    }
                }
            }
        }
        $names = array_values(array_unique($names));
        usort($names, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return $names;
    }

    /**
     * @param  array<mixed>  $payload
     * @param  list<string>  $known
     * @return array<mixed>
     */
    public static function payload(array $payload, array $known = []): array
    {
        array_walk_recursive($payload, function (mixed &$value) use ($known): void {
            if (is_string($value)) {
                $value = self::text($value, $known);
            }
        });

        return $payload;
    }
}
