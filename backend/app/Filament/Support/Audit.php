<?php

namespace App\Filament\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * One line of the audit log for a staff action (who · what · on which record · why). The trace id is added by
 * AppServiceProvider, so «تتبّع طلب» finds it. Read in «سجل التدقيق».
 */
final class Audit
{
    /** @param array<string, mixed> $properties */
    public static function log(string $event, string $description, ?Model $subject = null, array $properties = []): void
    {
        $entry = activity('admin')->causedBy(Staff::user())->event($event)->withProperties($properties);
        if ($subject !== null) {
            $entry->performedOn($subject);
        }
        $entry->log($description);
    }
}
