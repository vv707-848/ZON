<?php

namespace App\Filament\Resources\AppConfigs\Pages;

use App\Filament\Resources\AppConfigs\AppConfigResource;
use App\Filament\Support\Audit;
use Filament\Resources\Pages\EditRecord;

class EditAppConfig extends EditRecord
{
    protected static string $resource = AppConfigResource::class;

    protected function afterSave(): void
    {
        Audit::log('app_config_updated', 'تعديل إصدار التطبيق أو الصيانة', $this->getRecord(), ['changes' => $this->getRecord()->getChanges()]);
    }
}
