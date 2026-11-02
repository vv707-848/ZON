<?php

namespace App\Ai\Masking;

use App\Support\ArabicText;

/**
 * De-identification before anything leaves for the model (§13): phone numbers, long digit runs (national ids,
 * accounts), e-mails, links, IBANs — and the names the caller knows (a client's name) become «العميل».
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

    /** @param list<string> $known names to hide */
    public static function text(string $text, array $known = []): string
    {
        $text = ArabicText::asciiDigits($text);
        foreach ($known as $name) {
            $name = trim($name);
            if (mb_strlen($name) >= 2) {
                $text = str_ireplace($name, 'العميل', $text);
            }
        }

        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text);
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
