<?php

namespace App\Filament\Resources\CommissionDues\Pages;

use App\Filament\Resources\CommissionDues\CommissionDueResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCommissionDues extends ListRecords
{
    protected static string $resource = CommissionDueResource::class;

    protected function getHeaderActions(): array
    {
        return static::getResource()::canCreate() ? [CreateAction::make()] : [];
    }
}
