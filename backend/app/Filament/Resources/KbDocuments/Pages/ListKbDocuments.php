<?php

namespace App\Filament\Resources\KbDocuments\Pages;

use App\Filament\Resources\KbDocuments\KbDocumentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKbDocuments extends ListRecords
{
    protected static string $resource = KbDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return static::getResource()::canCreate() ? [CreateAction::make()] : [];
    }
}
