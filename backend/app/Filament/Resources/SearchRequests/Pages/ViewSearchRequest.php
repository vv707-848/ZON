<?php

namespace App\Filament\Resources\SearchRequests\Pages;

use App\Filament\Resources\SearchRequests\SearchRequestResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewSearchRequest extends ViewRecord
{
    protected static string $resource = SearchRequestResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load('customer');
    }

    protected function getHeaderActions(): array
    {
        return SearchRequestResource::moderationActions();
    }
}
