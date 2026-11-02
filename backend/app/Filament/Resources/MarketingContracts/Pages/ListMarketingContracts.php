<?php

namespace App\Filament\Resources\MarketingContracts\Pages;

use App\Filament\Resources\MarketingContracts\MarketingContractResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMarketingContracts extends ListRecords
{
    protected static string $resource = MarketingContractResource::class;

    protected function getHeaderActions(): array
    {
        return static::getResource()::canCreate() ? [CreateAction::make()] : [];
    }
}
