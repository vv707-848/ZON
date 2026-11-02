<?php

namespace App\Filament\Resources\Packages;

use App\Enums\AreaRequestsAccess;
use App\Enums\PackageAudience;
use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Packages\Pages\EditPackage;
use App\Filament\Resources\Packages\Pages\ListPackages;
use App\Filament\Support\Columns;
use App\Models\Package;
use App\Models\Subscription;
use App\Support\Money;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * «الباقات وحدودها»: prices, balances and every limit the code reads (packages table) — a change here is live at
 * once, without a new app release. Prices are gross (what the user pays), in riyals here, halalas in the table.
 */
class PackageResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = Package::class;

    protected static ?string $modelLabel = 'باقة';

    protected static ?string $pluralModelLabel = 'الباقات وحدودها';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 1;

    protected static function viewPermission(): Permission
    {
        return Permission::ManagePackages;
    }

    public static function canCreate(): bool
    {
        return false;                                     // the code knows each plan by its code; new plans come with code
    }

    public static function form(Schema $schema): Schema
    {
        $count = fn (string $name, string $label) => TextInput::make($name)->label($label)->integer()->minValue(0)->maxValue(65000)->required();

        return $schema->components([
            Section::make('الباقة')->columns(3)->schema([
                TextInput::make('name')->label('الاسم')->required()->maxLength(80),
                TextInput::make('price_halalas')->label('السعر (ريال، شامل)')->numeric()->minValue(0)->required()
                    ->formatStateUsing(fn ($state) => $state === null ? null : (new Money((int) $state))->riyals())
                    ->dehydrateStateUsing(fn ($state) => Money::sar((string) $state)->halalas),
                Toggle::make('is_active')->label('معروضة'),
                TagsInput::make('features')->label('سطور بطاقة الباقة')->columnSpanFull(),
            ]),
            Section::make('الأرصدة في كل فترة')->columns(3)->schema([
                $count('featured_quota', 'تمييز'), $count('urgent_listing_quota', '«عاجل» للعروض'), $count('urgent_request_quota', 'طلبات عاجلة'),
            ]),
            Section::make('الحدود')->columns(3)->schema([
                TextInput::make('listing_limit')->label('أقصى عدد عروض (فارغ = بلا حد)')->integer()->minValue(0),
                TextInput::make('inventory_limit')->label('أقصى مخزون (فارغ = بلا حد)')->integer()->minValue(0),
                $count('preferences_limit', 'عدد الرغبات'),
                Select::make('area_requests')->label('طلبات المنطقة')->options(AreaRequestsAccess::options())->required(),
                Toggle::make('can_search_requests')->label('البحث الذكي في الطلبات'),
                Toggle::make('can_manage_owners')->label('إدارة الملاك'),
            ]),
            Section::make('حدود الذكاء اليومية (للحساب؛ الجهاز ×1.5 والعنوان ×3)')->columns(4)->schema([
                $count('ai_search_daily', 'البحث'), $count('ai_matching_daily', 'المطابقة'), $count('ai_summary_daily', 'التلخيص'), $count('ai_chat_daily', 'الشات'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->label('الباقة'),
                TextColumn::make('code')->label('الرمز')->badge()->color('gray'),
                TextColumn::make('audience')->label('لمن')->formatStateUsing(fn (PackageAudience $state) => $state->label()),
                Columns::money('price_halalas', 'السعر'),
                Columns::enum('period', 'الفترة'),
                TextColumn::make('quotas')->label('الأرصدة')->state(fn (Package $p) => "{$p->featured_quota} · {$p->urgent_listing_quota} · {$p->urgent_request_quota}"),
                TextColumn::make('subscribers')->label('مشتركون الآن')->state(fn (Package $p) => Subscription::query()->current()->where('package_id', $p->id)->count()),
                IconColumn::make('is_active')->label('معروضة')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPackages::route('/'), 'edit' => EditPackage::route('/{record}/edit')];
    }
}
