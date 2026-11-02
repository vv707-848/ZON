<?php

namespace App\Filament\Resources\OwnerCases;

use App\Actions\Owner\OwnerCaseFlow;
use App\Enums\ContractStatus;
use App\Enums\OwnerCaseStage;
use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Listings\ListingResource;
use App\Filament\Resources\OwnerCases\Pages\ListOwnerCases;
use App\Filament\Support\Columns;
use App\Models\OwnerCase;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** «ملفات الملاك والتراخيص»: each owner's path — contract (REGA · Nafath) → fee → licence → listing → sale. */
class OwnerCaseResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = OwnerCase::class;

    protected static ?string $modelLabel = 'ملف مالك';

    protected static ?string $pluralModelLabel = 'ملفات الملاك والتراخيص';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?int $navigationSort = 5;

    protected static function viewPermission(): Permission
    {
        return Permission::ViewPayments;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['customer', 'licence']))
            ->columns([
                TextColumn::make('customer.name')->label('المالك')->searchable(),
                TextColumn::make('deed_number')->label('الصك')->searchable(),
                Columns::enum('stage', 'المرحلة', ['active' => 'success', 'closed' => 'gray', 'contract' => 'warning', 'fee' => 'warning']),
                Columns::enum('contract_status', 'العقد', ['approved' => 'success', 'rejected' => 'danger', 'expired' => 'danger', 'submitted' => 'warning']),
                TextColumn::make('contract_ref')->label('مرجع الهيئة')->placeholder('—')->toggleable(),
                TextColumn::make('licence.licence_number')->label('الترخيص')->placeholder('—'),
                TextColumn::make('listing_id')->label('العرض')->placeholder('—')->url(fn (OwnerCase $c) => $c->listing_id ? ListingResource::getUrl('view', ['record' => $c->listing_id]) : null),
                TextColumn::make('commission_percent')->label('السعي ٪'),
                TextColumn::make('last_checked_at')->label('آخر متابعة')->since()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('stage')->label('المرحلة')->options(OwnerCaseStage::options()),
                SelectFilter::make('contract_status')->label('العقد')->options(ContractStatus::options()),
            ])
            ->recordActions([
                Action::make('sync')->label('متابعة الآن')->icon('heroicon-o-arrow-path')
                    ->visible(fn (OwnerCase $c) => $c->stage === OwnerCaseStage::Contract)
                    ->action(fn (OwnerCase $record) => app(OwnerCaseFlow::class)->sync($record)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListOwnerCases::route('/')];
    }
}
