<?php

namespace App\Filament\Resources\Customers;

use App\Enums\AccountStatus;
use App\Enums\BoostKind;
use App\Enums\CustomerRole;
use App\Enums\FalStatus;
use App\Enums\NafathStatus;
use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\ListingsRelationManager;
use App\Filament\Resources\Customers\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Customers\RelationManagers\SubscriptionsRelationManager;
use App\Filament\Support\Columns;
use App\Models\City;
use App\Models\Customer;
use App\Services\Billing\Entitlements;
use App\Support\Permissions\Permission;
use App\Support\Phone;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * «المستخدمون»: every account of the app, all roles. Staff see the account, its licences, plan and the COUNTS of
 * its private desk; the desk's content opens only with the sensitive permission, and each look is audited.
 */
class CustomerResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = Customer::class;

    protected static ?string $slug = 'customers';

    protected static ?string $modelLabel = 'حساب';

    protected static ?string $pluralModelLabel = 'المستخدمون';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Accounts;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 1;

    protected static function viewPermission(): Permission
    {
        return Permission::ViewCustomers;
    }

    protected static function managePermission(): Permission
    {
        return Permission::ManageCustomers;
    }

    public static function canCreate(): bool
    {
        return false;                                     // accounts sign up in the app; developers: «المطوّرون»
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('بيانات الحساب')->columns(2)->schema([
                TextInput::make('name')->label('الاسم')->required()->maxLength(120),
                Select::make('city')->label('المدينة')->options(fn () => array_combine(array_keys(City::catalog()), array_keys(City::catalog())))->required(),
                Toggle::make('is_tester')->label('حساب اختبار (يتجاوز وضع الصيانة)'),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('الحساب')->columns(3)->schema([
                TextEntry::make('name')->label('الاسم'),
                TextEntry::make('username')->label('اسم المستخدم')->copyable(),
                TextEntry::make('phone')->label('الجوال')->formatStateUsing(fn (string $state) => Phone::local($state))->copyable(),
                Columns::enumEntry('role', 'الدور'),
                Columns::enumEntry('status', 'الحالة'),
                TextEntry::make('city')->label('المدينة'),
                TextEntry::make('suspended_reason')->label('سبب الإيقاف')->placeholder('—'),
                TextEntry::make('is_tester')->label('حساب اختبار')->formatStateUsing(fn (bool $state) => $state ? 'نعم' : 'لا'),
                TextEntry::make('created_at')->label('التسجيل')->dateTime(),
                TextEntry::make('last_seen_at')->label('آخر نشاط')->since()->placeholder('—'),
            ]),
            Section::make('التراخيص والتحقق')->columns(3)->schema([
                TextEntry::make('fal_number')->label('رخصة فال')->placeholder('—'),
                Columns::enumEntry('fal_status', 'حالة فال'),
                TextEntry::make('fal_expires_at')->label('انتهاء فال')->date()->placeholder('—'),
                Columns::enumEntry('nafath_status', 'نفاذ'),
                TextEntry::make('nafath_verified_at')->label('تاريخ التحقق')->dateTime()->placeholder('—'),
            ]),
            Section::make('الباقة')->columns(3)->schema([
                TextEntry::make('plan')->label('الباقة الحالية')->state(fn (Customer $record) => Entitlements::for($record)->package()->name),
                TextEntry::make('plan_end')->label('تنتهي')->state(fn (Customer $record) => Entitlements::for($record)->subscription()?->ends_at?->toDateString() ?? '—'),
                TextEntry::make('balances')->label('الأرصدة')->state(fn (Customer $record) => collect(Entitlements::for($record)->balances())
                    ->map(fn (int $n, string $k) => BoostKind::from($k)->label().": {$n}")->implode(' · ')),
            ]),
            Section::make('الأعداد (المحتوى الخاص لا يظهر هنا)')->columns(4)->schema([
                TextEntry::make('listings_count')->label('العروض')->state(fn (Customer $r) => $r->listings()->count()),
                TextEntry::make('requests_count')->label('الطلبات')->state(fn (Customer $r) => $r->searchRequests()->count()),
                TextEntry::make('preferences_count')->label('الرغبات')->state(fn (Customer $r) => $r->preferences()->count()),
                TextEntry::make('followers_count')->label('المتابِعون')->state(fn (Customer $r) => $r->followers()->count()),
                TextEntry::make('clients_count')->label('العملاء')->state(fn (Customer $r) => $r->clients()->count()),
                TextEntry::make('owners_count')->label('الملاك')->state(fn (Customer $r) => $r->propertyOwners()->count()),
                TextEntry::make('inventory_count')->label('المخزون')->state(fn (Customer $r) => $r->inventoryItems()->count()),
                TextEntry::make('tasks_count')->label('المهام')->state(fn (Customer $r) => $r->tasks()->count()),
                TextEntry::make('rating')->label('التقييم')->state(fn (Customer $r) => round((float) $r->ratingsReceived()->whereNull('hidden_at')->avg('stars'), 1).' ('.$r->ratingsReceived()->whereNull('hidden_at')->count().')'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('anonymized_at'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')->label('الاسم')->searchable()->limit(30),
                TextColumn::make('username')->label('اسم المستخدم')->searchable()->toggleable(),
                // Only a search with digits looks at phones: '' would match every number (LIKE '%%').
                Columns::phone()->searchable(query: fn (Builder $q, string $search) => ($digits = ltrim((string) preg_replace('/\D/', '', $search), '0')) === ''
                    ? $q : $q->where('phone', 'like', "%{$digits}%")),
                Columns::enum('role', 'الدور'),
                Columns::enum('status', 'الحالة', Columns::STATUS_COLORS),
                TextColumn::make('city')->label('المدينة')->toggleable(),
                Columns::enum('fal_status', 'فال', Columns::STATUS_COLORS)->toggleable(),
                Columns::enum('nafath_status', 'نفاذ', Columns::STATUS_COLORS)->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_tester')->label('اختبار')->boolean()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('التسجيل')->date()->sortable(),
                TextColumn::make('last_seen_at')->label('آخر نشاط')->since()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('role')->label('الدور')->options(CustomerRole::options()),
                SelectFilter::make('status')->label('الحالة')->options(AccountStatus::options()),
                SelectFilter::make('fal_status')->label('فال')->options(FalStatus::options()),
                SelectFilter::make('nafath_status')->label('نفاذ')->options(NafathStatus::options()),
                SelectFilter::make('city')->label('المدينة')->options(fn () => array_combine(array_keys(City::catalog()), array_keys(City::catalog()))),
                TernaryFilter::make('is_tester')->label('حساب اختبار'),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [ListingsRelationManager::class, SubscriptionsRelationManager::class, PaymentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
