<?php

namespace App\Support;

/** Arabic text compared the way people write it: alef forms, ta marbuta, ya, tatweel, diacritics, digits. */
final class ArabicText
{
    private const MAP = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي', 'ـ' => '',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    public static function normalize(string $text): string
    {
        $text = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text); // harakat
        $text = strtr($text, self::MAP);

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    /** Arabic and Persian digits as ASCII (phone numbers, prices typed in Arabic). */
    public static function asciiDigits(string $text): string
    {
        return strtr($text, array_filter(self::MAP, fn (string $to) => ctype_digit($to)));
    }

    /** @param iterable<string> $phrases */
    public static function containsAny(string $text, iterable $phrases): bool
    {
        $haystack = ' '.self::normalize($text).' ';
        foreach ($phrases as $phrase) {
            $needle = self::normalize($phrase);
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
