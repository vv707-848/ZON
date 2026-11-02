<?php

namespace App\Enums;

/**
 * The ten property types of the app (lib/data/catalog/property_types.dart — its only source). Which fields a
 * type uses mirrors the app's specsForType(), so the API asks for exactly what the app's form shows.
 */
enum PropertyType: string
{
    use Concerns;

    case Apartment = 'apartment';
    case Studio = 'studio';
    case Floor = 'floor';             // a whole floor with its own entrance
    case Villa = 'villa';
    case House = 'house';             // traditional single-family house
    case Resthouse = 'resthouse';     // rest house / leisure compound
    case Land = 'land';
    case Building = 'building';
    case Shop = 'shop';
    case Office = 'office';

    /** Retired keys of older app versions and their replacement (kRetiredTypes). */
    public const RETIRED = ['townhouse' => 'villa', 'duplex' => 'villa', 'penthouse' => 'apartment', 'room' => 'apartment'];

    public function label(): string
    {
        return match ($this) {
            self::Apartment => 'شقة',
            self::Studio => 'استوديو',
            self::Floor => 'دور مستقل',
            self::Villa => 'فيلا',
            self::House => 'بيت شعبي',
            self::Resthouse => 'استراحة',
            self::Land => 'أرض',
            self::Building => 'عمارة',
            self::Shop => 'محل',
            self::Office => 'مكتب',
        };
    }

    public function plural(): string
    {
        return match ($this) {
            self::Apartment => 'شقق',
            self::Studio => 'استوديوهات',
            self::Floor => 'أدوار مستقلة',
            self::Villa => 'فلل',
            self::House => 'بيوت شعبية',
            self::Resthouse => 'استراحات',
            self::Land => 'أراضي',
            self::Building => 'عمائر',
            self::Shop => 'محلات',
            self::Office => 'مكاتب',
        };
    }

    /**
     * The specification fields of the type (land area · built area · rooms · land use · floor · floors · age ·
     * families/singles · annual income), as in the app's form.
     *
     * @return list<string> API field names
     */
    public function fields(): array
    {
        return match ($this) {
            self::Villa, self::Floor, self::House, self::Resthouse => ['area_land', 'area_built', 'bedrooms', 'bathrooms', 'halls', 'age', 'occupancy'],
            self::Land => ['area_land', 'land_use'],
            self::Building => ['area_land', 'area_built', 'floors', 'age', 'annual_income'],
            self::Shop => ['area_built', 'age'],
            self::Studio => ['area_built', 'floor_level', 'floor_number', 'age', 'occupancy'],
            self::Office => ['area_built', 'bedrooms', 'bathrooms', 'halls', 'floor_level', 'floor_number', 'age'],
            self::Apartment => ['area_built', 'bedrooms', 'bathrooms', 'halls', 'floor_level', 'floor_number', 'age', 'occupancy'],
        };
    }

    /** Types a project may be (kProjectTypes) and a unit model may be (kProjectUnitTypes). */
    public function forProjects(): bool
    {
        return ! in_array($this, [self::Shop, self::Office], true);
    }

    public function forUnits(): bool
    {
        return $this->forProjects() && $this !== self::Building;
    }
}
