<?php

namespace App\SuperAgent;

use App\Support\Settings\Setting;
use App\Support\Settings\Settings;

/**
 * A market report: built from the daily snapshots and opted-in wishes only, and every row stands for at least
 * Setting::MarketReportMinRequests requests (never fewer than 5) so no person can be singled out.
 */
abstract class MarketTemplate extends Template
{
    public static function minimumGroup(): int
    {
        return max(5, Settings::int(Setting::MarketReportMinRequests));
    }
}
