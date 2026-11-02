<?php

namespace App\Filament\Resources\UserBlocks\Pages;

use App\Filament\Resources\UserBlocks\UserBlockResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUserBlocks extends ListRecords
{
    protected static string $resource = UserBlockResource::class;

    protected function getHeaderActions(): array
    {
        return static::getResource()::canCreate() ? [CreateAction::make()] : [];
    }
}
