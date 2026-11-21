<?php

namespace App\Ai\Retrieval;

/**
 * The conditions a wish states outright, and the offers that say the opposite (owner, 21 Nov 2026) — the agents'
 * own rules (agents/zon_agents/conditions.py), line for line; both are held to docs/contracts/fixtures/conditions.json.
 * Need::fallback takes such an offer out when no model ranked the list, as the agents do before the model reads it.
 *
 * Only these, said in so many words by both texts: the ground floor («دور أرضي»; an upper floor says the opposite,
 * a whole house does not) · a whole house, only as an alternative after «أو» · singles or families («عوائل فقط» to
 * singles) · furnished or not · a lift («بدون مصعد»). «أو» joins alternatives, any of which keeps the condition; an
 * alternative these rules do not read leaves it to the model. «أفضّل»، «يفضل»… make what follows a preference: it
 * orders, it never takes out. An offer that says nothing on a condition does not contradict it.
 */
final class Conditions
{
    private const W = '(?<![\p{L}\p{N}_])';

    private const E = '(?![\p{L}\p{N}_])';

    private const P = '(?:و|ف)?(?:ب|ل)?(?:ال)?';

    private const HOUSE_TYPES = ['villa', 'house', 'building', 'resthouse'];

    private const FLAT_TYPES = ['apartment', 'studio'];

    /** @return array<string, string> what a wish can ask for, in the order the agents read them */
    private static function asked(): array
    {
        $w = self::W;
        $e = self::E;
        $p = self::P;

        return [
            'unfurnished' => '/(?:غير|بدون|بلا|مو|مب|ما) ?(?:مفروش|مفروشه|موثث|موثثه|اثاث|عفش)/u',
            'ground' => "/{$w}{$p}(?:ارضي|ارضيه){$e}/u",
            'house' => "/{$w}{$p}(?:بيت|فيلا|فيله|فله|دبلكس|دوبلكس){$e}/u",
            'singles' => "/{$w}{$p}(?:عزاب|عزابي|اعزب|عزوبي|عزوبيه){$e}/u",
            'families' => "/{$w}{$p}(?:عايله|عايلتي|عايلات|عوايل|عوائل|اسره|اسرتي|عايلي|عايليه){$e}/u",
            'furnished' => "/{$w}{$p}(?:مفروش|مفروشه|موثث|موثثه){$e}/u",
            'lift' => "/{$w}{$p}(?:مصعد|اصنصير){$e}/u",
        ];
    }

    /** The agents' numbers.norm: no marks or tatweel, one form of each letter, Arabic-Indic digits as digits. */
    public static function norm(string $text): string
    {
        $text = (string) preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $text);
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);

        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text)));
    }

    /** @return list<array{int, int, string}> each condition named in [text]: its start and end (letters) and what it asks */
    private static function terms(string $text): array
    {
        $found = [];
        $covered = [];
        foreach (self::asked() as $name => $pattern) {
            preg_match_all($pattern, $text, $all, PREG_OFFSET_CAPTURE);
            foreach ($all[0] as [$word, $byte]) {
                $start = mb_strlen(substr($text, 0, $byte));
                $end = $start + mb_strlen($word);
                if (array_filter($covered, fn (array $r) => $start >= $r[0] && $start < $r[1]) !== []) {
                    continue;   // «غير مفروشة» is unfurnished, not furnished
                }
                if ($name === 'lift' && preg_match('/(?:بدون|بلا|ما يحتاج|مو لازم|ما يهم|مايهم) ?(?:مصعد|اصنصير)/u', mb_substr($text, max(0, $start - 12), $end - max(0, $start - 12))) === 1) {
                    continue;
                }
                $found[] = [$start, $end, $name];
                $covered[] = [$start, $end];
            }
        }
        usort($found, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $found;
    }

    /** @return list<list<string>> the conditions [wish] states outright, each the alternatives any one of which keeps it */
    public static function of(string $wish): array
    {
        $w = self::W;
        $e = self::E;
        $out = [];
        $clauses = preg_split("/[،,.؛;!؟?\n]+|{$w}(?:لكن|بس|مع){$e}/u", self::norm($wish)) ?: [];
        foreach ($clauses as $clause) {
            $terms = self::terms($clause);
            if ($terms === []) {
                continue;
            }
            $prefer = preg_match("/{$w}(?:و|ف)?(?:افضل|يفضل|تفضل|نفضل|افضله|يفضله|حبذا|ياليت|يا ليت|يستحسن|ويستحسن|لو امكن|ان امكن|اذا امكن|احسن|الافضل){$e}/u", $clause, $pm, PREG_OFFSET_CAPTURE) === 1
                ? mb_strlen(substr($clause, 0, $pm[0][1])) : null;
            $group = array_keys($terms);   // each term its own group, by index
            $unknown = [];
            preg_match_all("/{$w}(?:او|ولا){$e}/u", $clause, $ors, PREG_OFFSET_CAPTURE);
            foreach ($ors[0] as [$or, $byte]) {
                $start = mb_strlen(substr($clause, 0, $byte));
                $end = $start + mb_strlen($or);
                $before = array_keys(array_filter($terms, fn (array $t) => $t[1] <= $start && $start - $t[1] <= 3));
                $after = array_keys(array_filter($terms, fn (array $t) => $t[0] >= $end && $t[0] - $end <= 3));
                if ($before !== [] && $after !== []) {
                    [$old, $new] = [$group[$after[0]], $group[end($before)]];
                    $group = array_map(fn (int $g) => $g === $old ? $new : $g, $group);
                } elseif ($before !== [] || $after !== []) {
                    $unknown[] = $group[($before ?: $after)[0]];
                }
            }
            $ids = array_values(array_diff(array_unique($group), $unknown));
            sort($ids);
            foreach ($ids as $g) {
                $members = array_values(array_filter(array_keys($terms), fn (int $i) => $group[$i] === $g));
                if (array_filter($members, fn (int $i) => $prefer !== null && $prefer < $terms[$i][0]) !== []) {
                    continue;   // preferred: it orders, it never takes out
                }
                $names = array_values(array_unique(array_map(fn (int $i) => $terms[$i][2], $members)));
                if ($names === ['house']) {
                    continue;   // «بيت» alone is the type the form asks: an alternative only, after «أو»
                }
                $out[] = $names;
            }
        }

        return $out;
    }

    /** @return array<string, bool> what an offer says, in so many words */
    public static function offer(string $text, ?string $kind = null): array
    {
        $w = self::W;
        $e = self::E;
        $p = self::P;
        $said = self::norm($text);
        $has = fn (string $pattern) => preg_match($pattern, $said) === 1;
        $families = $has("/{$w}{$p}(?:عوايل|عوائل|عايلات|عايله|عايلي|عايليه|اسر){$e}/u");
        $singles = $has("/{$w}{$p}(?:عزاب|عزابي|اعزب|عزوبي){$e}/u");
        $notFurnished = '/(?:غير|بدون|بلا|مو) ?(?:مفروش|مفروشه|موثث|موثثه|اثاث|عفش)/u';
        $whole = in_array($kind, self::HOUSE_TYPES, true) || $has("/{$w}{$p}(?:فيلا|فيله|فله|بيت|دبلكس|دوبلكس|دورين|ثلاث ادوار|عماره){$e}/u");
        $floor = '(?:دور|الدور|طابق|الطابق|بالدور|بالطابق|في الدور)';

        return [
            'upper' => $has("/{$floor} ?(?:ال)?(?:اول|اولي|ثاني|ثالث|رابع|خامس|سادس|علوي|عالي|اخير|\\d+){$e}|{$w}(?:علوي|روف|سطح){$e}/u"),
            'ground' => $has("/{$w}{$p}(?:ارضي|ارضيه){$e}/u"),
            'whole' => $whole,
            'flat' => in_array($kind, self::FLAT_TYPES, true) || ($has("/{$w}{$p}(?:شقه|شقق|استديو|ستوديو|ملحق){$e}/u") && ! $whole),
            'families_only' => $families && ! $singles,
            'singles_only' => $singles && ! $families,
            'furnished' => preg_match("/{$w}{$p}(?:مفروش|مفروشه|موثث|موثثه){$e}/u", (string) preg_replace($notFurnished, ' ', $said)) === 1,
            'unfurnished' => $has($notFurnished),
            'no_lift' => $has('/(?:بدون|بلا|لا يوجد|ما فيه|مافيه|ما فيها|مافيها|ولا يوجد) ?(?:مصعد|اصنصير)/u'),
        ];
    }

    /** @param array<string, bool> $o */
    private static function against(string $asked, array $o): bool
    {
        return match ($asked) {
            'ground' => $o['upper'] && ! $o['ground'] && ! $o['whole'],
            'house' => $o['flat'] && ! $o['whole'],
            'singles' => $o['families_only'],
            'families' => $o['singles_only'],
            'furnished' => $o['unfurnished'],
            'unfurnished' => $o['furnished'] && ! $o['unfurnished'],
            'lift' => $o['no_lift'],
            default => false,
        };
    }

    /** [offerText] says the opposite of a condition [wish] states outright: every alternative of one of them. */
    public static function contradicts(string $wish, string $offerText, ?string $offerKind = null): bool
    {
        $said = self::offer($offerText, $offerKind);
        foreach (self::of($wish) as $alternatives) {
            if (array_filter($alternatives, fn (string $a) => ! self::against($a, $said)) === []) {
                return true;
            }
        }

        return false;
    }
}
