<?php

namespace App\SuperAgent;

use App\Models\ExecReport;
use App\Models\User;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;

/** At most Setting::SuperAgentDailyCap reports per superadmin per Riyadh day (each costs model time). */
final class DailyCap
{
    public static function left(User $staff): int
    {
        $today = ExecReport::query()->where('staff_id', $staff->id)->where('created_at', '>=', now('Asia/Riyadh')->startOfDay()->utc())->count();

        return max(0, Settings::int(Setting::SuperAgentDailyCap) - $today);
    }
}
