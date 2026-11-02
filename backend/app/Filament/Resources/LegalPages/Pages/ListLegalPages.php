<?php

namespace App\Filament\Resources\LegalPages\Pages;

use App\Filament\Resources\LegalPages\LegalPageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLegalPages extends ListRecords
{
    protected static string $resource = LegalPageResource::class;

    protected function getHeaderActions(): array
    {
        return static::getResource()::canCreate() ? [CreateAction::make()] : [];
    }
}
