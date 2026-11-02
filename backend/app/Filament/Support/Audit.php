<?php

namespace App\Filament\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of the audit log for a staff action (who · what · on which record · why). The trace id is added by
 * AppServiceProvider, so «تتبّع طلب» finds it. Read in «سجل التدقيق».
 */
final class Audit
{
    /**
     * @param  array<string, mixed>  $properties
     * @param  ?User  $by  the member of staff, when the caller knows him (otherwise the one signed in to the panel)
     */
    public static function log(string $event, string $description, ?Model $subject = null, array $properties = [], ?User $by = null): void
    {
        $entry = activity('admin')->causedBy($by ?? Staff::user())->event($event)->withProperties($properties);
        if ($subject !== null) {
            $entry->performedOn($subject);
        }
        $entry->log($description);
    }
}
