<?php

namespace App\Filament\Resources\Cities\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** A city's neighbourhoods; turning one off hides it from new forms (existing records keep their value). */
class NeighborhoodsRelationManager extends RelationManager
{
    protected static string $relationship = 'neighborhoods';

    protected static ?string $title = 'الأحياء';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('الحي')->required()->maxLength(80),
            Toggle::make('is_active')->label('ظاهر')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('الحي')->searchable(),
            IconColumn::make('is_active')->label('ظاهر')->boolean(),
        ])->headerActions([CreateAction::make()])->recordActions([EditAction::make()]);
    }
}
