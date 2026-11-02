<?php

namespace App\Filament\Resources\Preferences;

use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Preferences\Pages\ListPreferences;
use App\Models\BuyerPreference;
use App\Models\City;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/** «الرغبات»: seekers' wishes — read only; the demand the matching and the twice-daily notices work from. */
class PreferenceResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = BuyerPreference::class;

    protected static ?string $slug = 'preferences';

    protected static ?string $modelLabel = 'رغبة';

    protected static ?string $pluralModelLabel = 'الرغبات';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Content;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?int $navigationSort = 4;

    protected static function viewPermission(): Permission
    {
        return Permission::ViewRequests;
    }

    protected static function managePermission(): ?Permission
    {
        return null;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('updated_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('customer'))
            ->columns([
                TextColumn::make('customer.name')->label('الباحث')->url(fn (BuyerPreference $r) => CustomerResource::getUrl('view', ['record' => $r->customer_id])),
                TextColumn::make('city')->label('المدينة'),
                TextColumn::make('neighborhoods')->label('الأحياء')->badge()->limitList(3),
                TextColumn::make('property_types')->label('الأنواع')->badge()->placeholder('كل الأنواع'),
                TextColumn::make('budget_max')->label('حتى')->numeric()->placeholder('—'),
                TextColumn::make('notes')->label('الملاحظات')->limit(40)->placeholder('—'),
                IconColumn::make('is_active')->label('نشطة')->boolean(),
                IconColumn::make('share_in_reports')->label('في تقارير السوق')->boolean()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('آخر تحديث')->since(),
            ])
            ->filters([
                SelectFilter::make('city')->label('المدينة')->options(fn () => array_combine(array_keys(City::catalog()), array_keys(City::catalog()))),
                TernaryFilter::make('is_active')->label('نشطة'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPreferences::route('/')];
    }
}
