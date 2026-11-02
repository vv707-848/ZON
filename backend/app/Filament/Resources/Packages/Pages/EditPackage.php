<?php

namespace App\Filament\Resources\Packages\Pages;

use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Support\Audit;
use Filament\Resources\Pages\EditRecord;

class EditPackage extends EditRecord
{
    protected static string $resource = PackageResource::class;

    protected function afterSave(): void
    {
        $record = $this->getRecord();
        Audit::log('package_updated', 'تعديل باقة', $record, ['changes' => $record->getChanges()]);
    }
}
