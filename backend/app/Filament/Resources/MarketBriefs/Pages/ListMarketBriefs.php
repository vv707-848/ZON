<?php

namespace App\Filament\Resources\MarketBriefs\Pages;

use App\Filament\Resources\MarketBriefs\MarketBriefResource;
use App\Filament\Support\Audit;
use App\Support\Accounting\Exports;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListMarketBriefs extends ListRecords
{
    protected static string $resource = MarketBriefResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('تقرير جديد'),
            // the paid briefs of a period, for the books kept in Daftra (as «تصدير لدفترة» in «الفواتير»)
            Action::make('export_briefs')->label('تصدير لدفترة')->icon(Heroicon::OutlinedArrowDownTray)
                ->schema([DatePicker::make('from')->label('من'), DatePicker::make('until')->label('إلى')])
                ->action(function (array $data) {
                    Audit::log('export_briefs', 'تصدير مبيعات تقارير المطوّرين', properties: array_filter($data));

                    return Exports::briefs($data['from'] ?? null, $data['until'] ?? null);
                }),
        ];
    }
}
