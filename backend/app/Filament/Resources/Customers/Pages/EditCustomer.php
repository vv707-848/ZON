<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\Audit;
use Filament\Resources\Pages\EditRecord;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function afterSave(): void
    {
        Audit::log('customer_edited', 'تعديل بيانات حساب', $this->getRecord(), ['fields' => array_keys($this->getRecord()->getChanges())]);
    }
}
