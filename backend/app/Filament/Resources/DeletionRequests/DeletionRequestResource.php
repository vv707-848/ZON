<?php

namespace App\Filament\Resources\DeletionRequests;

use App\Actions\Accounts\CancelDeletion;
use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\DeletionRequests\Pages\ListDeletionRequests;
use App\Filament\Support\Audit;
use App\Filament\Support\Staff;
use App\Models\DeletionRequest;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/** «طلبات حذف الحساب»: the grace period, its end, and cancelling it for the user. */
class DeletionRequestResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = DeletionRequest::class;

    protected static ?string $modelLabel = 'طلب حذف';

    protected static ?string $pluralModelLabel = 'طلبات حذف الحساب';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Accounts;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserMinus;

    protected static ?int $navigationSort = 4;

    protected static function viewPermission(): Permission
    {
        return Permission::ViewCustomers;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('customer'))
            ->columns([
                TextColumn::make('customer.name')->label('الحساب'),
                TextColumn::make('requested_at')->label('الطلب')->dateTime(),
                TextColumn::make('purge_at')->label('الحذف النهائي')->dateTime(),
                TextColumn::make('state')->label('الحالة')->badge()->state(fn (DeletionRequest $r) => match (true) {
                    $r->purged_at !== null => 'حُذف', $r->cancelled_at !== null => 'أُلغي', default => 'في المهلة',
                })->color(fn (string $state) => $state === 'في المهلة' ? 'warning' : 'gray'),
                TextColumn::make('cancelled_by')->label('ألغاه')->placeholder('—'),
            ])
            ->filters([TernaryFilter::make('pending')->label('في المهلة')->queries(
                true: fn ($q) => $q->whereNull('cancelled_at')->whereNull('purged_at'),
                false: fn ($q) => $q->where(fn ($w) => $w->whereNotNull('cancelled_at')->orWhereNotNull('purged_at')),
            )])
            ->recordActions([
                Action::make('cancel')->label('إلغاء الطلب')->icon('heroicon-o-arrow-uturn-left')->requiresConfirmation()
                    ->visible(fn (DeletionRequest $r) => $r->isPending() && Staff::can(Permission::ManageCustomers))
                    ->action(function (DeletionRequest $record) {
                        app(CancelDeletion::class)($record, 'staff:'.Staff::user()->id);
                        Audit::log('deletion_cancelled', 'إلغاء حذف حساب', $record->customer);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDeletionRequests::route('/')];
    }
}
