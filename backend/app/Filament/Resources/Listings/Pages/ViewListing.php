<?php

namespace App\Filament\Resources\Listings\Pages;

use App\Filament\Resources\Listings\ListingResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewListing extends ViewRecord
{
    protected static string $resource = ListingResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['customer', 'licence', 'media', 'history']);
    }

    protected function getHeaderActions(): array
    {
        return ListingResource::moderationActions();
    }
}
