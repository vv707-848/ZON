<?php

namespace App\Filament\Resources\ContentReports\Pages;

use App\Filament\Resources\ContentReports\ContentReportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListContentReports extends ListRecords
{
    protected static string $resource = ContentReportResource::class;

    protected function getHeaderActions(): array
    {
        return static::getResource()::canCreate() ? [CreateAction::make()] : [];
    }
}
