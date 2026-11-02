<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Support\Columns;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SubscriptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'subscriptions';

    protected static ?string $title = 'الاشتراكات';

    public function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('package'))
            ->columns([
                TextColumn::make('package.name')->label('الباقة'),
                Columns::enum('source', 'المصدر'),
                TextColumn::make('starts_at')->label('من')->date(),
                TextColumn::make('ends_at')->label('إلى')->date()->placeholder('مفتوح'),
                TextColumn::make('cancelled_at')->label('أُلغي')->date()->placeholder('—'),
            ]);
    }
}
