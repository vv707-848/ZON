<?php

namespace App\Filament\Resources\IndexEvents\Pages;

use App\Filament\Resources\IndexEvents\IndexEventResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIndexEvents extends ListRecords
{
    protected static string $resource = IndexEventResource::class;

    protected function getHeaderActions(): array
    {
        return static::getResource()::canCreate() ? [CreateAction::make()] : [];
    }
}
