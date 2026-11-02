<?php

namespace App\Filament\Resources\SearchRequests\Pages;

use App\Filament\Resources\SearchRequests\SearchRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSearchRequests extends ListRecords
{
    protected static string $resource = SearchRequestResource::class;

    protected function getHeaderActions(): array
    {
        return static::getResource()::canCreate() ? [CreateAction::make()] : [];
    }
}
