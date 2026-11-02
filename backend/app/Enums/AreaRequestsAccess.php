<?php

namespace App\Enums;

/** What a broker sees of his area's requests (packages.area_requests). */
enum AreaRequestsAccess: string
{
    use Concerns;

    case None = 'none';
    case Preview = 'preview';
    case Full = 'full';

    public function label(): string
    {
        return match ($this) {
            self::None => 'بلا وصول',
            self::Preview => 'معاينة',
            self::Full => 'كامل',
        };
    }
}
