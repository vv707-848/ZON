<?php

namespace App\Enums;

/** Why content was reported. */
enum ReportReason: string
{
    use Concerns;

    case Misleading = 'misleading';
    case Sold = 'sold';
    case Duplicate = 'duplicate';
    case BadLicence = 'bad_licence';
    case Inappropriate = 'inappropriate';
    case Fraud = 'fraud';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Misleading => 'مضلل',
            self::Sold => 'مباع',
            self::Duplicate => 'مكرر',
            self::BadLicence => 'ترخيص غير صحيح',
            self::Inappropriate => 'غير لائق',
            self::Fraud => 'احتيال',
            self::Other => 'أخرى',
        };
    }
}
