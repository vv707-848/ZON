<?php

namespace App\Filament\Resources\DeletionRequests\Pages;

use App\Filament\Resources\DeletionRequests\DeletionRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDeletionRequests extends ListRecords
{
    protected static string $resource = DeletionRequestResource::class;

    protected function getHeaderActions(): array
    {
        return static::getResource()::canCreate() ? [CreateAction::make()] : [];
    }
}
