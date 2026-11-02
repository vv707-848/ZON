<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Payments\PaymentResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['customer', 'invoice']);
    }

    protected function getHeaderActions(): array
    {
        return [PaymentResource::refundAction()];
    }
}
