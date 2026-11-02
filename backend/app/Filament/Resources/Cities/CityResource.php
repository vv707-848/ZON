<?php

namespace App\Filament\Resources\Cities;

use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Cities\Pages\CreateCity;
use App\Filament\Resources\Cities\Pages\EditCity;
use App\Filament\Resources\Cities\Pages\ListCities;
use App\Filament\Resources\Cities\RelationManagers\NeighborhoodsRelationManager;
use App\Models\City;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** «المدن والأحياء»: the catalog of the app's forms and filters (GET /catalog) — a new neighbourhood is live at once. */
class CityResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = City::class;

    protected static ?string $modelLabel = 'مدينة';

    protected static ?string $pluralModelLabel = 'المدن والأحياء';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Knowledge;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?int $navigationSort = 3;

    protected static function viewPermission(): Permission
    {
        return Permission::ManageReference;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('المدينة')->required()->maxLength(80)->unique(ignoreRecord: true),
            TextInput::make('sort')->label('الترتيب')->integer()->default(0),
            Toggle::make('is_active')->label('ظاهرة في التطبيق')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')
            ->modifyQueryUsing(fn ($query) => $query->withCount('neighborhoods'))
            ->columns([
                TextColumn::make('name')->label('المدينة'),
                TextColumn::make('neighborhoods_count')->label('الأحياء'),
                IconColumn::make('is_active')->label('ظاهرة')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [NeighborhoodsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListCities::route('/'), 'create' => CreateCity::route('/create'), 'edit' => EditCity::route('/{record}/edit')];
    }
}
