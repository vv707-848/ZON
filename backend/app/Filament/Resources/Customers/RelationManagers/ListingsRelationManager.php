<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Listings\ListingResource;
use App\Filament\Support\Columns;
use App\Models\Listing;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ListingsRelationManager extends RelationManager
{
    protected static string $relationship = 'listings';

    protected static ?string $title = 'العروض';

    public function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('title')->label('العنوان')->limit(40),
                Columns::enum('status', 'الحالة', Columns::STATUS_COLORS),
                TextColumn::make('neighborhood')->label('الحي'),
                TextColumn::make('price')->label('السعر')->numeric(),
                TextColumn::make('published_at')->label('النشر')->date()->placeholder('—'),
            ])
            ->recordUrl(fn (Listing $record) => ListingResource::getUrl('view', ['record' => $record]));
    }
}
