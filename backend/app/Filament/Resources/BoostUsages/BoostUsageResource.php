<?php

namespace App\Filament\Resources\BoostUsages;

use App\Enums\BoostKind;
use App\Enums\BoostVia;
use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\BoostUsages\Pages\ListBoostUsages;
use App\Filament\Support\Columns;
use App\Models\BoostUsage;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** «استخدام التمييز»: every «مميز» and «عاجل», from a balance, a payment or a staff grant («منح تمييز» on the listing). */
class BoostUsageResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = BoostUsage::class;

    protected static ?string $modelLabel = 'تمييز';

    protected static ?string $pluralModelLabel = 'استخدام التمييز';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?int $navigationSort = 7;

    protected static function viewPermission(): Permission
    {
        return Permission::ViewPayments;
    }

    protected static function managePermission(): ?Permission
    {
        return null;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('customer'))
            ->columns([
                TextColumn::make('customer.name')->label('الحساب')->searchable(),
                Columns::enum('kind', 'النوع'),
                TextColumn::make('target_type')->label('على')->formatStateUsing(fn (string $state, BoostUsage $b) => ($state === 'listing' ? 'عرض' : 'طلب')." #{$b->target_id}"),
                Columns::enum('via', 'المصدر', ['quota' => 'success', 'payment' => 'primary', 'grant' => 'warning']),
                TextColumn::make('starts_at')->label('من')->dateTime(),
                TextColumn::make('active_until')->label('حتى')->dateTime(),
            ])
            ->filters([
                SelectFilter::make('kind')->label('النوع')->options(BoostKind::options()),
                SelectFilter::make('via')->label('المصدر')->options(BoostVia::options()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListBoostUsages::route('/')];
    }
}
