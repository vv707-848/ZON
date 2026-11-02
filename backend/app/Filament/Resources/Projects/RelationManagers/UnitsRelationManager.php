<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Support\Columns;
use App\Models\ProjectUnit;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UnitsRelationManager extends RelationManager
{
    protected static string $relationship = 'units';

    protected static ?string $title = 'نماذج الوحدات';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('licence'))
            ->columns([
                TextColumn::make('model_name')->label('النموذج'),
                Columns::enum('property_type', 'النوع'),
                TextColumn::make('price')->label('السعر')->numeric(),
                TextColumn::make('available_count')->label('المتاح'),
                Columns::enum('text_review', 'الصياغة'),
                TextColumn::make('licence.licence_number')->label('الترخيص')->placeholder('—'),
                TextColumn::make('desc_clean')->label('الوصف المعتمد')->limit(40)->placeholder('—')
                    ->tooltip(fn (ProjectUnit $record) => $record->desc_raw),
            ]);
    }
}
