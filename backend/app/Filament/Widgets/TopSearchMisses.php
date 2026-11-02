<?php

namespace App\Filament\Widgets;

use App\Models\SearchMiss;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Where people searched and found nothing over 30 days — supply to bring in. */
class TopSearchMisses extends TableWidget
{
    protected static ?string $heading = 'أكثر البحثات بلا نتيجة (30 يوماً)';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SearchMiss::query()->where('created_at', '>=', now()->subDays(30))
                ->select('city', 'neighborhood', 'property_type', DB::raw('COUNT(*) AS misses'), DB::raw('MAX(id) AS id'), DB::raw('MAX(created_at) AS last_at'))
                ->groupBy('city', 'neighborhood', 'property_type'))
            ->defaultSort('misses', 'desc')
            ->defaultKeySort(false)          // a grouped query has no row key to add to ORDER BY (MySQL only_full_group_by)
            ->paginated([10, 25])
            ->columns([
                TextColumn::make('city')->label('المدينة'),
                TextColumn::make('neighborhood')->label('الحي')->placeholder('—'),
                TextColumn::make('property_type')->label('النوع')->placeholder('كل الأنواع'),
                TextColumn::make('misses')->label('مرات')->sortable(),
                TextColumn::make('last_at')->label('آخرها')->since(),
            ]);
    }
}
