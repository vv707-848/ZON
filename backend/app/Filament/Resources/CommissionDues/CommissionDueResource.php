<?php

namespace App\Filament\Resources\CommissionDues;

use App\Enums\CommissionStatus;
use App\Enums\CustomerRole;
use App\Enums\ListingStatus;
use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\CommissionDues\Pages\CreateCommissionDue;
use App\Filament\Resources\CommissionDues\Pages\ListCommissionDues;
use App\Filament\Support\Audit;
use App\Filament\Support\Columns;
use App\Filament\Support\Staff;
use App\Models\CommissionDue;
use App\Models\Listing;
use App\Support\Permissions\Permission;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * «العمولات المستحقة»: what is owed to Zon after a sale — the owner's percent and the developer's per unit come by
 * themselves; a free broker's share of his commission (السعي) is recorded here by staff after the deal.
 */
class CommissionDueResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = CommissionDue::class;

    protected static ?string $modelLabel = 'عمولة';

    protected static ?string $pluralModelLabel = 'العمولات المستحقة';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 6;

    protected static function viewPermission(): Permission
    {
        return Permission::ViewPayments;
    }

    protected static function managePermission(): Permission
    {
        return Permission::ManagePayments;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('listing_id')->label('العرض المباع (وسيط بلا اشتراك)')->required()->searchable()
                ->getSearchResultsUsing(fn (string $search) => Listing::query()->where('status', ListingStatus::Sold)
                    ->whereHas('customer', fn ($q) => $q->where('role', CustomerRole::Broker))->where('title', 'like', "%{$search}%")->limit(20)->pluck('title', 'id'))
                ->getOptionLabelUsing(fn ($value) => Listing::query()->find($value)?->title),   // without it saving failed (Filament checks the choice)
            TextInput::make('sale_price')->label('سعر البيع (ريال)')->numeric()->minValue(1)->rule('decimal:0,2')->required(),
            TextInput::make('brokerage_fee')->label('السعي الذي تقاضاه الوسيط (ريال)')->numeric()->minValue(1)->rule('decimal:0,2')->required()->dehydrated(false),
            TextInput::make('percent')->label('حصة زون من السعي (٪)')->numeric()->minValue(0)->maxValue(100)->required()
                ->default(fn () => Settings::string(Setting::BrokerFreeSharePercent)),
            Textarea::make('notes')->label('ملاحظات')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $settle = fn (string $name, string $label, CommissionStatus $to, string $color) => Action::make($name)->label($label)->color($color)
            ->visible(fn (CommissionDue $c) => $c->status === CommissionStatus::Due && Staff::can(Permission::ManagePayments))
            ->schema([Textarea::make('note')->label('ملاحظة')->required()->maxLength(500)])
            ->action(function (CommissionDue $record, array $data) use ($to) {
                $record->forceFill(['status' => $to, 'paid_at' => $to === CommissionStatus::Paid ? now() : null, 'notes' => trim($record->notes."\n".$data['note'])])->save();
                Audit::log("commission_{$to->value}", 'تسوية عمولة', $record, ['note' => $data['note']]);
            });

        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['customer']))
            ->columns([
                TextColumn::make('customer.name')->label('الحساب')->searchable(),
                TextColumn::make('source_type')->label('المصدر')->formatStateUsing(fn (string $state) => ['owner_case' => 'سعي مالك', 'marketing_contract' => 'عقد مطوّر', 'listing' => 'حصة وسيط'][$state] ?? $state),
                TextColumn::make('sale_price')->label('سعر البيع')->numeric(),
                TextColumn::make('percent')->label('النسبة ٪'),
                Columns::money('amount_halalas', 'المستحق'),
                Columns::enum('status', 'الحالة', Columns::STATUS_COLORS),
                TextColumn::make('created_at')->label('التاريخ')->date(),
            ])
            ->filters([SelectFilter::make('status')->label('الحالة')->options(CommissionStatus::options())])
            ->recordActions([$settle('paid', 'تحصيل', CommissionStatus::Paid, 'success'), $settle('waive', 'إعفاء', CommissionStatus::Waived, 'gray')]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCommissionDues::route('/'), 'create' => CreateCommissionDue::route('/create')];
    }
}
