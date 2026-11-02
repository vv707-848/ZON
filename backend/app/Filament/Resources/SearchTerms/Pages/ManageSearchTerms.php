<?php

namespace App\Filament\Resources\SearchTerms\Pages;

use App\Filament\Resources\SearchTerms\SearchTermResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSearchTerms extends ManageRecords
{
    protected static string $resource = SearchTermResource::class;

    protected function getHeaderActions(): array
    {
        return static::getResource()::canCreate() ? [CreateAction::make()] : [];
    }
}
