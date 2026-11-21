<?php

namespace App\Ai\Retrieval;

/**
 * The conditions a wish states outright, and the offers that say the opposite (owner, 21 Nov 2026) — the agents'
 * own rules (agents/zon_agents/conditions.py), line for line; both are held to docs/contracts/fixtures/conditions.json.
 * Need::fallback takes such an offer out when no model ranked the list, as the agents do before the model reads it.
 *
 * Only these, said in so many words by both texts: the floor («دور أرضي»، «الدور الأول»: another floor says the
 * opposite, a whole house does not) · a whole house, only as an alternative after «أو» · singles or families
 * («عوائل فقط» to singles, and «لا تقبل العزاب» to a single man — never to a family) · furnished or not · a lift
 * («بدون مصعد»). «أو» joins alternatives, any of which keeps the condition («أرضي أو أول»: either floor, never the
 * third); an alternative these rules do not read leaves it to the model. «أفضّل»، «يفضل»… make what follows a
 * preference up to a must («أفضّل حي الشفا ولازم مصعد»: the lift is a condition). A category the wish denies is no
 * condition. An offer that says nothing on a condition does not contradict it.
 */
final class Conditions
{
    private const W = '(?<![\p{L}\p{N}_])';

    private const E = '(?![\p{L}\p{N}_])';

    private const P = '(?:و|ف)?(?:ب|ل)?(?:ال)?';

    private const FLOOR = 'floor:';

    private const HOUSE_TYPES = ['villa', 'house', 'building', 'resthouse'];

    private const FLAT_TYPES = ['apartment', 'studio'];

    private const ORDINALS = ['ارضي' => 0, 'ارضيه' => 0, 'اول' => 1, 'اولي' => 1, 'ثاني' => 2, 'ثانيه' => 2, 'ثالث' => 3, 'ثالثه' => 3, 'رابع' => 4, 'خامس' => 5, 'سادس' => 6];

    private const ORDINAL = '(?:ال)?(?:ارضيه|ارضي|اولي|اول|ثانيه|ثاني|ثالثه|ثالث|رابع|خامس|سادس|\d{1,2})';

    private const FLOOR_WORD = '(?:و|ف)?(?:ب|ل|في )?(?:ال)?(?:دور|طابق)';

    private const DENY = ['لا', 'ما', 'مو', 'مب', 'غير', 'ممنوع', 'يمنع', 'بدون', 'بلا', 'ليس', 'ليست', 'مانقبل', 'مايقبل', 'ماتقبل', 'مانأجر', 'ماناجر'];

    /** The agents' numbers.norm: no marks or tatweel, one form of each letter, Arabic-Indic digits as digits. */
    public static function norm(string $text): string
    {
        $text = (string) preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $text);
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);

        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text)));
    }

    /** «الدور الأول» · «دور ثاني» · «الطابق 3» · «أرضي» alone */
    private static function aFloor(): string
    {
        return '/'.self::W.self::FLOOR_WORD.' ?(?P<n>'.self::ORDINAL.')'.self::E.'|'.self::W.self::P.'(?P<g>ارضيه|ارضي)'.self::E.'/u';
    }

    private static function floorOf(string $word): int
    {
        $word = (string) preg_replace('/^ال/u', '', $word);

        return ctype_digit($word) ? (int) $word : self::ORDINALS[$word];
    }

    /** One of DENY within the two words before [start] (letters). */
    private static function denied(string $text, int $start): bool
    {
        $words = preg_split('/\s+/u', trim(mb_substr($text, 0, $start)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_intersect(array_slice($words, -2), self::DENY) !== [];
    }

    /** @return list<array{string, int, int, array<array-key, array{string, int}>}> each match: its words, its start and end in letters, and its groups */
    private static function matches(string $pattern, string $text, int $from = 0): array
    {
        preg_match_all($pattern, $text, $all, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $out = [];
        foreach ($all as $m) {
            $start = mb_strlen(substr($text, 0, $m[0][1]));
            if ($start >= $from) {
                $out[] = [$m[0][0], $start, $start + mb_strlen($m[0][0]), $m];
            }
        }

        return $out;
    }

    /** @return array{bool, bool} whether [text] names the category of [pattern] as it is, and whether it turns it away */
    private static function said(string $pattern, string $text): array
    {
        $found = array_map(fn (array $m) => self::denied($text, $m[1]), self::matches($pattern, $text));

        return [in_array(false, $found, true), in_array(true, $found, true)];
    }

    /** @return list<array{int, int, string}> each condition named in [text]: its start, end and what it asks */
    private static function terms(string $text): array
    {
        $w = self::W;
        $e = self::E;
        $p = self::P;
        $found = [];
        $add = function (int $start, int $end, string $name) use (&$found): void {
            foreach ($found as [$s, $x]) {
                if (($start >= $s && $start < $x) || ($s >= $start && $s < $end)) {
                    return;
                }
            }
            $found[] = [$start, $end, $name];
        };
        foreach (self::matches(self::aFloor(), $text) as [, $s, $x, $m]) {
            $add($s, $x, self::FLOOR.self::floorOf(($m['n'][0] ?? '') !== '' ? $m['n'][0] : $m['g'][0]));
        }
        foreach (self::matches("/{$w}(?:و)?(?P<n>".self::ORDINAL."){$e}/u", $text) as [, $s, $x, $m]) {
            $add($s, $x, 'floor?:'.self::floorOf($m['n'][0]));
        }
        $asked = [
            'unfurnished' => '/(?:غير|بدون|بلا|مو|مب|ما) ?(?:مفروش|مفروشه|موثث|موثثه|اثاث|عفش)/u',
            'house' => "/{$w}{$p}(?:بيت|فيلا|فيله|فله|دبلكس|دوبلكس){$e}/u",
            'singles' => "/{$w}{$p}(?:عزاب|عزابي|اعزب|عزوبي|عزوبيه){$e}/u",
            'families' => "/{$w}{$p}(?:عايله|عايلتي|عايلات|عوايل|عوائل|اسره|اسرتي|عايلي|عايليه){$e}/u",
            'furnished' => "/{$w}{$p}(?:مفروش|مفروشه|موثث|موثثه){$e}/u",
            'lift' => "/{$w}{$p}(?:مصعد|اصنصير){$e}/u",
        ];
        foreach ($asked as $name => $pattern) {
            foreach (self::matches($pattern, $text) as [, $s, $x]) {
                if ($name === 'lift' && preg_match('/(?:بدون|بلا|ما يحتاج|مو لازم|ما يهم|مايهم) ?(?:مصعد|اصنصير)/u', mb_substr($text, max(0, $s - 12), $x - max(0, $s - 12))) === 1) {
                    continue;
                }
                if (in_array($name, ['singles', 'families'], true) && self::denied($text, $s)) {
                    continue;   // «مو سكن عزاب»: the wish denies it, no condition
                }
                $add($s, $x, $name);
            }
        }
        usort($found, fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $found;
    }

    private static function bare(string $name): bool
    {
        return str_starts_with($name, 'floor?');
    }

    /** @return list<list<string>> the conditions [wish] states outright, each the alternatives any one of which keeps it */
    public static function of(string $wish): array
    {
        $w = self::W;
        $e = self::E;
        $out = [];
        foreach (preg_split("/[،,.؛;!؟?\n]+|{$w}(?:لكن|بس|مع){$e}/u", self::norm($wish)) ?: [] as $clause) {
            $terms = self::terms($clause);
            if (array_filter($terms, fn (array $t) => ! self::bare($t[2])) === []) {
                continue;
            }
            $soft = [];
            foreach (self::matches("/{$w}(?:و|ف)?(?:افضل|يفضل|تفضل|نفضل|افضله|يفضله|حبذا|ياليت|يا ليت|يستحسن|ويستحسن|لو امكن|ان امكن|اذا امكن|احسن|الافضل){$e}/u", $clause) as [, $ps, $pe]) {
                $must = self::matches("/{$w}(?:و|ف)?(?:لازم|ضروري|ضروريه|شرط|بشرط|لابد|لا بد|مطلوب|اساسي|اكيد){$e}/u", $clause, $pe)[0][1] ?? null;
                foreach ($terms as $i => $t) {
                    if ($ps < $t[0] && ($must === null || $t[0] < $must)) {
                        $soft[$i] = true;
                    }
                }
            }
            $group = array_keys($terms);
            $unknown = [];
            $floorsOk = [];
            foreach (self::matches("/{$w}(?:او|ولا){$e}/u", $clause) as [, $os, $oe]) {
                $before = array_keys(array_filter($terms, fn (array $t) => $t[1] <= $os && $os - $t[1] <= 3));
                $after = array_keys(array_filter($terms, fn (array $t) => $t[0] >= $oe && $t[0] - $oe <= 3));
                if ($before !== [] && $after !== []) {
                    [$left, $right] = [end($before), $after[0]];
                    $lf = str_starts_with($terms[$left][2], 'floor');
                    $rf = str_starts_with($terms[$right][2], 'floor');
                    if ($lf && $rf) {
                        $floorsOk[$left] = $floorsOk[$right] = true;
                    } elseif (self::bare($terms[$left][2]) || self::bare($terms[$right][2])) {
                        $unknown[] = $group[self::bare($terms[$left][2]) ? $right : $left];

                        continue;
                    }
                    [$old, $new] = [$group[$right], $group[$left]];
                    $group = array_map(fn (int $g) => $g === $old ? $new : $g, $group);
                } elseif ($before !== [] || $after !== []) {
                    $side = ($before ?: $after)[0];
                    if (! self::bare($terms[$side][2])) {
                        $unknown[] = $group[$side];
                    }
                }
            }
            $ids = array_values(array_diff(array_unique($group), $unknown));
            sort($ids);
            foreach ($ids as $g) {
                $members = array_values(array_filter(array_keys($terms), fn (int $i) => $group[$i] === $g && (! self::bare($terms[$i][2]) || isset($floorsOk[$i]))));
                if ($members === [] || array_filter($members, fn (int $i) => isset($soft[$i])) !== []) {
                    continue;   // an ordinal alone («أول ساكن»), or preferred: it orders, it never takes out
                }
                $names = array_values(array_unique(array_map(fn (int $i) => str_replace('floor?:', self::FLOOR, $terms[$i][2]), $members)));
                if ($names === ['house']) {
                    continue;   // «بيت» alone is the type the form asks: an alternative only, after «أو»
                }
                $out[] = $names;
            }
        }

        return $out;
    }

    /** @return array{floors: list<int>, upper: bool, whole: bool, flat: bool, families_only: bool, singles_only: bool, no_singles: bool, no_families: bool, furnished: bool, unfurnished: bool, no_lift: bool} */
    public static function offer(string $text, ?string $kind = null): array
    {
        $w = self::W;
        $e = self::E;
        $p = self::P;
        $said = self::norm($text);
        $has = fn (string $pattern) => preg_match($pattern, $said) === 1;
        [$families, $noFamilies] = self::said("/{$w}{$p}(?:عوايل|عوائل|عايلات|عايله|عايلي|عايليه|اسر){$e}/u", $said);
        [$singles, $noSingles] = self::said("/{$w}{$p}(?:عزاب|عزابي|اعزب|عزوبي){$e}/u", $said);
        $notFurnished = '/(?:غير|بدون|بلا|مو) ?(?:مفروش|مفروشه|موثث|موثثه|اثاث|عفش)/u';
        $whole = in_array($kind, self::HOUSE_TYPES, true) || $has("/{$w}{$p}(?:فيلا|فيله|فله|بيت|دبلكس|دوبلكس|دورين|ثلاث ادوار|عماره){$e}/u");
        $floors = array_values(array_unique(array_map(fn (array $m) => self::floorOf(($m[3]['n'][0] ?? '') !== '' ? $m[3]['n'][0] : $m[3]['g'][0]), self::matches(self::aFloor(), $said))));

        return [
            'floors' => $floors,
            'upper' => $has("/{$w}(?:علوي|روف|سطح){$e}/u"),
            'whole' => $whole,
            'flat' => in_array($kind, self::FLAT_TYPES, true) || ($has("/{$w}{$p}(?:شقه|شقق|استديو|ستوديو|ملحق){$e}/u") && ! $whole),
            'families_only' => ($families || $noSingles) && ! $singles,
            'singles_only' => ($singles || $noFamilies) && ! $families,
            'no_singles' => $noSingles,
            'no_families' => $noFamilies,
            'furnished' => preg_match("/{$w}{$p}(?:مفروش|مفروشه|موثث|موثثه){$e}/u", (string) preg_replace($notFurnished, ' ', $said)) === 1,
            'unfurnished' => $has($notFurnished),
            'no_lift' => $has('/(?:بدون|بلا|لا يوجد|ما فيه|مافيه|ما فيها|مافيها|ولا يوجد) ?(?:مصعد|اصنصير)/u'),
        ];
    }

    /** @param array{floors: list<int>, upper: bool, whole: bool, flat: bool, families_only: bool, singles_only: bool, no_singles: bool, no_families: bool, furnished: bool, unfurnished: bool, no_lift: bool} $o */
    private static function against(string $asked, array $o): bool
    {
        if (str_starts_with($asked, self::FLOOR)) {   // on another floor it states; a whole house has them all; «علوي» is not the ground
            $n = (int) substr($asked, strlen(self::FLOOR));

            return match (true) {
                $o['whole'] => false,
                $o['floors'] !== [] => ! in_array($n, $o['floors'], true),
                default => $o['upper'] && $n === 0,
            };
        }

        return match ($asked) {
            'house' => $o['flat'] && ! $o['whole'],
            'singles' => $o['families_only'] || $o['no_singles'],
            'families' => $o['singles_only'] || $o['no_families'],
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
