<?php

namespace App\Filament\Resources\LegacyClaims\Pages;

use App\Filament\Resources\LegacyClaims\LegacyClaimResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLegacyClaims extends ListRecords
{
    protected static string $resource = LegacyClaimResource::class;

    protected function getHeaderActions(): array
    {
        return static::getResource()::canCreate() ? [CreateAction::make()] : [];
    }
}
