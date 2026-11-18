<?php

namespace App\Filament\Resources\MarketBriefs;

use App\Enums\CustomerRole;
use App\Enums\PropertyType;
use App\Enums\Purpose;
use App\Filament\NavigationGroup;
use App\Filament\Resources\MarketBriefs\Pages\CreateMarketBrief;
use App\Filament\Resources\MarketBriefs\Pages\ListMarketBriefs;
use App\Filament\Resources\MarketBriefs\Pages\ViewMarketBrief;
use App\Filament\Support\Audit;
use App\Filament\Support\Staff;
use App\Models\City;
use App\Models\Customer;
use App\Models\MarketBrief;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * «تقارير المطوّرين» (superadmin): a market brief for one city made for a developer and sold to him — the approved
 * market reports as they run today, frozen, with their headline numbers, opened on the website by its link until it
 * expires. The market's data is the platform's most sensitive asset after its users: the superadmin alone sees it.
 */
class MarketBriefResource extends Resource
{
    protected static ?string $model = MarketBrief::class;

    protected static ?string $slug = 'market-briefs';

    protected static ?string $modelLabel = 'تقرير مطوّر';

    protected static ?string $pluralModelLabel = 'تقارير المطوّرين';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Administration;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?int $navigationSort = 7;

    public static function canViewAny(): bool
    {
        return Staff::isSuperadmin();
    }

    public static function canView(Model $record): bool
    {
        return Staff::isSuperadmin();
    }

    public static function canCreate(): bool
    {
        return Staff::isSuperadmin();
    }

    public static function canEdit(Model $record): bool
    {
        return false;   // what was sold does not change: a new brief is made instead
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $cities = array_keys(City::catalog());

        return $schema->components([
            Section::make('السوق')->description('التقرير لمدينة واحدة، ولحي ونوع إن اخترتهما. يُبنى من تقارير السوق المعتمدة كما هي اليوم، وكل صف فيه لا يقل عن الحد الأدنى للطلبات.')
                ->columns(3)->schema([
                    Select::make('city')->label('المدينة')->required()->options(array_combine($cities, $cities))->live()
                        ->afterStateUpdated(fn (Set $set) => $set('neighborhood', null)),
                    Select::make('neighborhood')->label('الحي (اختياري)')->placeholder('كل الأحياء')
                        ->options(fn (Get $get) => array_combine($names = City::catalog()[(string) $get('city')] ?? [], $names)),
                    Select::make('purpose')->label('شراء أو استئجار')->required()->default(Purpose::Sale->value)->options(Purpose::options())->selectablePlaceholder(false),
                    Select::make('property_type')->label('نوع العقار (اختياري)')->placeholder('كل الأنواع')->options(PropertyType::options()),
                    Select::make('days')->label('فترة الطلب')->required()->default(90)->selectablePlaceholder(false)
                        ->options([30 => '30 يوماً', 90 => '90 يوماً', 180 => '180 يوماً', 365 => 'سنة']),
                ]),
            Section::make('البيع')->columns(3)->schema([
                Select::make('customer_id')->label('حساب المطوّر (اختياري)')->searchable()
                    ->options(fn () => Customer::query()->where('role', CustomerRole::Developer)->whereNull('anonymized_at')->orderBy('name')->pluck('name', 'id')->all())
                    ->live()->afterStateUpdated(fn (Set $set, ?string $state) => $state === null ? null : $set('buyer_name', Customer::query()->find($state)?->name)),
                TextInput::make('buyer_name')->label('اسم المشتري كما يظهر في التقرير')->required()->maxLength(120),
                TextInput::make('price')->label('السعر (ريال، اختياري)')->numeric()->minValue(0)->maxValue(10_000_000),
                Select::make('valid_days')->label('يفتح الرابط لمدة')->required()->default(30)->selectablePlaceholder(false)
                    ->options([7 => 'أسبوع', 30 => '30 يوماً', 90 => '90 يوماً', 365 => 'سنة']),
                Textarea::make('analyst_note')->label('ملاحظة المحلل (اختياري، تظهر باسم «ملاحظة فريق زون»)')->maxLength(2000)->rows(3)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label('الرقم')->searchable(),
                TextColumn::make('buyer_name')->label('المشتري')->searchable(),
                TextColumn::make('scope')->label('السوق')->state(fn (MarketBrief $record) => $record->scope()),
                TextColumn::make('price_halalas')->label('السعر')->formatStateUsing(fn (?int $state) => $state === null ? '—' : (new Money($state))->riyals().' ر.س')->placeholder('—'),
                TextColumn::make('paid_at')->label('الدفع')->date()->placeholder('لم يُسجَّل'),
                TextColumn::make('expires_at')->label('يفتح حتى')->date()->color(fn (MarketBrief $record) => $record->isOpen() ? null : 'danger'),
                TextColumn::make('views_count')->label('فُتح'),
                TextColumn::make('created_at')->label('أُنشئ')->date(),
            ])
            ->recordActions([self::paidAction(), self::closeAction()]);
    }

    /** The transfer arrived: its amount and day, for the accounts (Daftra). */
    public static function paidAction(): Action
    {
        return Action::make('paid')->label('تسجيل الدفع')->icon(Heroicon::OutlinedBanknotes)
            ->visible(fn (MarketBrief $record) => $record->paid_at === null)
            ->schema([
                TextInput::make('price')->label('المبلغ المستلم (ريال)')->required()->numeric()->minValue(1)->maxValue(10_000_000)
                    ->default(fn (MarketBrief $record) => $record->price_halalas === null ? null : (new Money($record->price_halalas))->riyals()),
                DatePicker::make('paid_on')->label('يوم الاستلام')->required()->default(now('Asia/Riyadh')->toDateString())->maxDate(now('Asia/Riyadh')->toDateString()),
            ])
            ->action(function (MarketBrief $record, array $data) {
                $record->forceFill(['price_halalas' => Money::fromRiyals((string) $data['price'])->halalas,
                    'paid_at' => CarbonImmutable::parse($data['paid_on'].' 12:00', 'Asia/Riyadh')->utc()])->save();
                Audit::log('market_brief_paid', 'تسجيل دفع تقرير مطوّر', $record, ['halalas' => $record->price_halalas]);
            });
    }

    /** Closes the link now (a link sent to the wrong person, a refund). */
    public static function closeAction(): Action
    {
        return Action::make('close')->label('إغلاق الرابط')->icon(Heroicon::OutlinedLockClosed)->color('danger')->requiresConfirmation()
            ->visible(fn (MarketBrief $record) => $record->isOpen())
            ->action(function (MarketBrief $record) {
                $record->forceFill(['expires_at' => now()])->save();
                Audit::log('market_brief_closed', 'إغلاق رابط تقرير مطوّر', $record);
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListMarketBriefs::route('/'), 'create' => CreateMarketBrief::route('/create'), 'view' => ViewMarketBrief::route('/{record}')];
    }
}
