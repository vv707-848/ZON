<?php

namespace App\Actions\Admin;

use App\Filament\Support\Audit;
use App\Filament\Support\Staff;
use App\Support\Money;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\DB;

/** «إعدادات المنصة»: only changed values are written, each with its old and new value in the audit log. */
final class UpdateSettings
{
    /** @param array<string, mixed> $values Setting value => form value (money in riyals) */
    public function __invoke(array $values): int
    {
        $changes = [];
        DB::transaction(function () use ($values, &$changes) {
            foreach (Setting::cases() as $setting) {
                if (! array_key_exists($setting->value, $values)) {
                    continue;
                }
                $new = $setting->type() === 'money' ? Money::sar((string) $values[$setting->value])->halalas : $values[$setting->value];
                $old = Settings::get($setting);
                if ($setting->type() === 'list') {
                    $new = array_values(array_filter(array_map('trim', is_array($new) ? $new : (preg_split('/\R/u', (string) $new) ?: []))));
                }
                if ($new == $old) {
                    continue;
                }
                Settings::put($setting, $new, Staff::user());
                $changes[$setting->value] = ['from' => $old, 'to' => $new];
            }
        });
        if ($changes !== []) {
            Audit::log('settings_updated', 'تعديل إعدادات المنصة', null, $changes);
        }

        return count($changes);
    }
}
