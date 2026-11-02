<?php

namespace App\Filament\Resources\OwnerCases\Pages;

use App\Filament\Resources\OwnerCases\OwnerCaseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOwnerCases extends ListRecords
{
    protected static string $resource = OwnerCaseResource::class;

    protected function getHeaderActions(): array
    {
        return static::getResource()::canCreate() ? [CreateAction::make()] : [];
    }
}
