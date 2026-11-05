<?php

namespace App\Support\Site;

use App\Enums\Purpose;
use App\Models\Listing;
use App\Models\ProjectUnit;
use Carbon\CarbonInterface;

/** How the website writes numbers, prices, areas and dates — as the app does: Latin digits, Riyadh time. */
final class Format
{
    private const MONTHS = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

    public static function number(float|int $value): string
    {
        return number_format((float) $value, fmod((float) $value, 1.0) === 0.0 ? 0 : 2);
    }

    /** 880,000 ريال · 50,000 ريال سنوياً (yearly rent). */
    public static function price(float|int $value, ?Purpose $purpose = null): string
    {
        return self::number($value).' ريال'.($purpose === Purpose::Rent ? ' سنوياً' : '');
    }

    /**
     * A count with its noun as Arabic says it: one, two, three to ten (plural), eleven and above (singular).
     * counted(1, 'غرفة واحدة', 'غرفتان', 'غرف', 'غرفة') → غرفة واحدة · غرفتان · 3 غرف · 12 غرفة
     */
    public static function counted(int $count, string $one, string $two, string $few, string $many): string
    {
        return match (true) {
            $count === 1 => $one,
            $count === 2 => $two,
            $count >= 3 && $count <= 10 => self::number($count).' '.$few,
            default => self::number($count).' '.$many,
        };
    }

    public static function rooms(int $count): string
    {
        return self::counted($count, 'غرفة واحدة', 'غرفتان', 'غرف', 'غرفة');
    }

    public static function units(int $count): string
    {
        return self::counted($count, 'وحدة واحدة', 'وحدتان', 'وحدات', 'وحدة');
    }

    public static function area(float|int $value): string
    {
        return self::number($value).' م²';
    }

    /** 5 نوفمبر 2026 (in Riyadh). */
    public static function date(CarbonInterface $date): string
    {
        $local = $date->copy()->setTimezone('Asia/Riyadh');

        return $local->day.' '.self::MONTHS[$local->month - 1].' '.$local->year;
    }

    /** 5 نوفمبر 2026، 3:40 م (in Riyadh). */
    public static function dateTime(CarbonInterface $date): string
    {
        $local = $date->copy()->setTimezone('Asia/Riyadh');

        return self::date($date).'، '.$local->format('g:i').' '.($local->hour < 12 ? 'ص' : 'م');
    }

    /**
     * The facts of a listing or a unit worth a line, in the app's order and words — only those it has.
     *
     * @return array<string, string> label => value
     */
    public static function specs(Listing|ProjectUnit $item): array
    {
        $get = fn (string $field) => $item->getAttribute($field);
        $floor = $item instanceof Listing && $item->floor_level !== null
            ? ($item->floor_level->value === 'number' && $item->floor_number !== null ? (string) $item->floor_number : $item->floor_level->label())
            : null;
        $rows = [
            'مساحة الأرض' => $get('area_land') !== null ? self::area((float) $get('area_land')) : null,
            'مسطح البناء' => $get('area_built') !== null ? self::area((float) $get('area_built')) : null,
            'المساحة' => $item instanceof ProjectUnit && $item->area !== null ? self::area((float) $item->area) : null,
            'غرف النوم' => $get('bedrooms') !== null ? (string) $get('bedrooms') : null,
            'دورات المياه' => $get('bathrooms') !== null ? (string) $get('bathrooms') : null,
            'الصالات' => $get('halls') !== null ? (string) $get('halls') : null,
            'الدور' => $floor,
            'عدد الأدوار' => $item instanceof Listing && $item->floors !== null ? (string) $item->floors : null,
            'عمر العقار' => $item instanceof Listing && $item->age !== null
                ? ($item->age === 0 ? 'جديد' : self::counted($item->age, 'سنة واحدة', 'سنتان', 'سنوات', 'سنة')) : null,
            'السكن' => $item instanceof Listing ? $item->occupancy?->label() : null,
            'الاستخدام' => $get('land_use')?->label(),
            'الدخل السنوي' => $item instanceof Listing && $item->annual_income !== null ? self::price((float) $item->annual_income) : null,
        ];

        return array_filter($rows, fn (?string $value) => $value !== null && $value !== '');
    }
}
