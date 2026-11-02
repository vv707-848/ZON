<?php

namespace App\Filament\Resources\BoostUsages\Pages;

use App\Filament\Resources\BoostUsages\BoostUsageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBoostUsages extends ListRecords
{
    protected static string $resource = BoostUsageResource::class;

    protected function getHeaderActions(): array
    {
        return static::getResource()::canCreate() ? [CreateAction::make()] : [];
    }
}
