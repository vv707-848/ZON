<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load('developer');
    }

    protected function getHeaderActions(): array
    {
        return ProjectResource::moderationActions();
    }
}
