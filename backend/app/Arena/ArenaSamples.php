<?php

namespace App\Arena;

use App\Enums\PropertyType;
use App\Enums\Purpose;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The samples of «ساحة الاختبار»: listings, requests and wishes written the way people write them here — types,
 * rooms, areas and prices that agree with each other and with the city, and descriptions in everyday Arabic,
 * so search and matching are tried on text like the market's. Each batch draws from its own seed: the same
 * batch id gives the same samples. No phone number, e-mail, link or name is ever written in them.
 */
final class ArenaSamples
{
    /** Sale price of a built square metre (SAR), Riyadh = the reference; other cities are scaled. */
    private const PER_METRE = 5200;

    private const CITY_SCALE = ['الرياض' => 1.0, 'جدة' => 0.92, 'مكة المكرمة' => 1.05, 'المدينة المنورة' => 0.9, 'الدمام' => 0.78, 'الخبر' => 0.88];

    /** type => [share of the samples, built area range, sale price scale, yearly rent range] */
    private const TYPES = [
        'apartment' => [30, [90, 220], 1.0, [28000, 85000]],
        'villa' => [22, [260, 520], 1.15, [85000, 220000]],
        'floor' => [12, [170, 320], 0.95, [45000, 110000]],
        'land' => [14, [300, 900], 0.5, null],
        'house' => [6, [200, 420], 0.6, [40000, 90000]],
        'building' => [4, [600, 1600], 0.9, null],
        'studio' => [4, [35, 70], 1.1, [18000, 36000]],
        'shop' => [4, [40, 160], 1.3, [40000, 150000]],
        'office' => [4, [60, 240], 1.2, [35000, 120000]],
    ];

    private const NEAR = ['قريب من المسجد', 'قريب من المدارس', 'قريب من الخدمات', 'قريب من طريق رئيسي', 'قريب من حديقة الحي', 'في شارع هادئ'];

    private const HOME = ['مطبخ راكب', 'مكيفات سبليت', 'مدخل مستقل', 'غرفة خادمة', 'غرفة سائق', 'سطح خاص', 'موقف خاص', 'مصعد', 'خزان علوي وأرضي', 'تشطيب ممتاز', 'إضاءة طبيعية'];

    private const VILLA = ['مجلس رجال مستقل', 'صالة نساء', 'حوش واسع', 'ملحق خارجي', 'مسبح', 'درج صالة', 'شقة علوية للإيجار'];

    private const NEED = [
        'للسكن مع العائلة', 'لأول بيت لنا', 'للاستثمار والتأجير', 'قريب من عملي', 'قريب من مدارس الأولاد',
        'ودي يكون فيه مجلس مستقل', 'أفضّل بناء جديد', 'ما عندي مشكلة بعمر البناء إذا السعر مناسب',
    ];

    private readonly Randomizer $random;

    public function __construct(string $batch)
    {
        $this->random = new Randomizer(new Mt19937(crc32($batch)));
    }

    /**
     * The columns of one listing.
     *
     * @param  list<string>  $neighborhoods
     * @return array<string, mixed>
     */
    public function listing(string $city, array $neighborhoods, int $rentPercent): array
    {
        $type = $this->type();
        [, [$min, $max], $scale, $rents] = self::TYPES[$type];
        $purpose = $rents !== null && $this->random->getInt(1, 100) <= $rentPercent ? Purpose::Rent : Purpose::Sale;
        $hood = $this->pick($neighborhoods);
        $area = $this->round($this->random->getInt($min, $max), 5);
        $built = $type !== 'land';
        $rooms = $built ? $this->rooms($type, $area) : null;
        $price = $purpose === Purpose::Rent
            ? $this->round((int) ($this->random->getInt($rents[0], $rents[1]) * $this->cityScale($city)), 1000)
            : $this->round((int) ($area * self::PER_METRE * $scale * $this->cityScale($city) * $this->random->getFloat(0.85, 1.2)), 5000);
        $age = $built ? $this->random->getInt(0, 25) : null;
        $label = PropertyType::from($type)->label();

        return [
            'purpose' => $purpose,
            'property_type' => $type,
            'title' => "{$label} ".($purpose === Purpose::Rent ? 'للإيجار' : 'للبيع')." في {$hood}",
            'city' => $city,
            'neighborhood' => $hood,
            'price' => $price,
            'negotiable' => $this->random->getInt(0, 1) === 1,
            'area_land' => $built && ! in_array($type, ['villa', 'house', 'building'], true) ? null : ($built ? $this->round((int) ($area * 1.3), 5) : $area),
            'area_built' => $built ? $area : null,
            'bedrooms' => $rooms,
            'bathrooms' => $rooms === null ? null : max(1, $rooms - $this->random->getInt(0, 1)),
            'halls' => $rooms === null ? null : ($rooms >= 4 ? 2 : 1),
            'age' => $age,
            'has_deed' => true,
            'bank_accepted' => $purpose === Purpose::Sale && $this->random->getInt(1, 10) <= 7,
            'desc_raw' => $this->describe($type, $purpose, $area, $rooms, $age),
        ];
    }

    /**
     * The columns of one search request (or, with `notes` for `description`, one wish).
     *
     * @param  list<string>  $neighborhoods
     * @return array<string, mixed>
     */
    public function request(string $city, array $neighborhoods, int $rentPercent): array
    {
        $type = $this->type(['land', 'building', 'shop', 'office', 'studio']);
        [, [$min, $max], $scale, $rents] = self::TYPES[$type];
        $purpose = $rents !== null && $this->random->getInt(1, 100) <= $rentPercent ? Purpose::Rent : Purpose::Sale;
        $hoods = $this->random->pickArrayKeys(array_flip($neighborhoods), min(count($neighborhoods), $this->random->getInt(1, 3)));
        $typical = (int) (($min + $max) / 2);
        $budget = $purpose === Purpose::Rent
            ? (int) ($rents[1] * $this->cityScale($city))
            : (int) ($typical * self::PER_METRE * $scale * $this->cityScale($city));
        $rooms = $this->rooms($type, $typical);

        return [
            'purpose' => $purpose,
            'city' => $city,
            'neighborhoods' => array_values(array_map('strval', $hoods)),
            'property_types' => [$type],
            'budget_min' => $this->round((int) ($budget * 0.6), $purpose === Purpose::Rent ? 1000 : 50000),
            'budget_max' => $this->round((int) ($budget * $this->random->getFloat(0.9, 1.25)), $purpose === Purpose::Rent ? 1000 : 50000),
            'rooms_min' => max(1, $rooms - 1),
            'description' => $this->need($type, $purpose),
        ];
    }

    private function need(string $type, Purpose $purpose): string
    {
        $need = $this->pick(self::NEED);
        $near = $this->pick(array_values(array_filter(self::NEAR, fn (string $n) => ! (str_contains($need, 'مدارس') && str_contains($n, 'مدارس')))));

        return 'أبحث عن '.PropertyType::from($type)->label().($purpose === Purpose::Rent ? ' للإيجار' : ' للشراء')."، {$need}، {$near}.";
    }

    private function describe(string $type, Purpose $purpose, int $area, ?int $rooms, ?int $age): string
    {
        $label = PropertyType::from($type)->label();
        $sentences = [($purpose === Purpose::Rent ? 'للإيجار ' : 'للبيع ')."{$label} بمساحة {$area} م²"];
        if ($rooms !== null) {
            $sentences[] = ($rooms === 2 ? 'غرفتا نوم' : "{$rooms} غرف نوم").' وصالة';
        }
        if ($age !== null) {
            $sentences[] = $age === 0 ? 'البناء جديد لم يُسكن' : "عمر البناء {$age} ".($age <= 10 ? 'سنوات' : 'سنة');
        }
        $pool = $type === 'villa' ? [...self::VILLA, ...self::HOME] : ($type === 'land' ? ['على شارعين', 'أرض مستوية', 'صك إلكتروني', 'تصلح سكني أو استثمار'] : self::HOME);
        foreach ($this->random->pickArrayKeys(array_flip($pool), min(count($pool), $this->random->getInt(2, 4))) as $feature) {
            $sentences[] = (string) $feature;
        }
        $sentences[] = $this->pick(self::NEAR);

        return implode('، ', $sentences).'.';
    }

    /** @param list<string> $except */
    private function type(array $except = []): string
    {
        $types = array_diff_key(self::TYPES, array_flip($except));
        $roll = $this->random->getInt(1, array_sum(array_column($types, 0)));
        foreach ($types as $type => [$share]) {
            if (($roll -= $share) <= 0) {
                return $type;
            }
        }

        return array_key_first($types);
    }

    private function rooms(string $type, int $area): int
    {
        return match ($type) {
            'studio', 'shop', 'office' => 1,
            'land' => 0,
            default => max(2, min(8, intdiv($area, 55) + $this->random->getInt(-1, 1))),
        };
    }

    private function cityScale(string $city): float
    {
        return self::CITY_SCALE[$city] ?? 0.7;
    }

    /** @param list<string> $items */
    private function pick(array $items): string
    {
        return $items[$this->random->getInt(0, count($items) - 1)];
    }

    private function round(int $value, int $step): int
    {
        return max($step, (int) (round($value / $step) * $step));
    }
}
