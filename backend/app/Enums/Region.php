<?php

namespace App\Enums;

/**
 * The administrative regions of Saudi Arabia, by their ISO 3166-2 code (cities.region; the code also names each
 * outline of the market map, resources/geo/saudi-regions.json). For the market reports alone: users pick a city and
 * a neighbourhood, never a region (the owner's decision).
 */
enum Region: string
{
    use Concerns;

    case Riyadh = 'SA-01';
    case Makkah = 'SA-02';
    case Madinah = 'SA-03';
    case Eastern = 'SA-04';
    case Qassim = 'SA-05';
    case Hail = 'SA-06';
    case Tabuk = 'SA-07';
    case NorthernBorders = 'SA-08';
    case Jazan = 'SA-09';
    case Najran = 'SA-10';
    case Bahah = 'SA-11';
    case Jawf = 'SA-12';
    case Asir = 'SA-14';

    public function label(): string
    {
        return match ($this) {
            self::Riyadh => 'منطقة الرياض',
            self::Makkah => 'منطقة مكة المكرمة',
            self::Madinah => 'منطقة المدينة المنورة',
            self::Eastern => 'المنطقة الشرقية',
            self::Qassim => 'منطقة القصيم',
            self::Hail => 'منطقة حائل',
            self::Tabuk => 'منطقة تبوك',
            self::NorthernBorders => 'منطقة الحدود الشمالية',
            self::Jazan => 'منطقة جازان',
            self::Najran => 'منطقة نجران',
            self::Bahah => 'منطقة الباحة',
            self::Jawf => 'منطقة الجوف',
            self::Asir => 'منطقة عسير',
        };
    }

    public static function fromLabel(string $label): ?self
    {
        foreach (self::cases() as $region) {
            if ($region->label() === $label) {
                return $region;
            }
        }

        return null;
    }
}
