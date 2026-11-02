<?php

namespace App\Filament\Resources\AppConfigs\Pages;

use App\Filament\Resources\AppConfigs\AppConfigResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAppConfigs extends ListRecords
{
    protected static string $resource = AppConfigResource::class;

    protected function getHeaderActions(): array
    {
        return static::getResource()::canCreate() ? [CreateAction::make()] : [];
    }
}
