<?php

namespace App\Filament\Resources\Subscriptions;

use App\Enums\SubscriptionSource;
use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Support\Audit;
use App\Filament\Support\Columns;
use App\Filament\Support\Staff;
use App\Models\Package;
use App\Models\Subscription;
use App\Services\Billing\Entitlements;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/** «الاشتراكات والمنح»: every period of a plan — bought, granted («منح باقة» on the account) or by contract. */
class SubscriptionResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = Subscription::class;

    protected static ?string $modelLabel = 'اشتراك';

    protected static ?string $pluralModelLabel = 'الاشتراكات والمنح';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 2;

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
            ->modifyQueryUsing(fn ($query) => $query->with(['customer', 'package', 'grantor']))
            ->columns([
                TextColumn::make('customer.name')->label('الحساب')->searchable()->url(fn (Subscription $s) => CustomerResource::getUrl('view', ['record' => $s->customer_id])),
                TextColumn::make('package.name')->label('الباقة'),
                Columns::enum('source', 'المصدر'),
                TextColumn::make('grantor.name')->label('منحها')->placeholder('—')->toggleable(),
                TextColumn::make('starts_at')->label('من')->date(),
                TextColumn::make('ends_at')->label('إلى')->date()->placeholder('مفتوح'),
                TextColumn::make('state')->label('الحالة')->badge()->state(fn (Subscription $s) => $s->cancelled_at ? 'ملغى' : ($s->isCurrent() ? 'سارٍ' : 'منتهٍ'))
                    ->color(fn (string $state) => $state === 'سارٍ' ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('source')->label('المصدر')->options(SubscriptionSource::options()),
                SelectFilter::make('package_id')->label('الباقة')->options(fn () => Package::query()->pluck('name', 'id')),
                TernaryFilter::make('current')->label('سارية الآن')->queries(true: fn ($q) => $q->current(), false: fn ($q) => $q->where(fn ($w) => $w->whereNotNull('cancelled_at')->orWhere('ends_at', '<=', now()))),
            ])
            ->recordActions([
                Action::make('cancel')->label('إلغاء')->color('danger')->requiresConfirmation()
                    ->visible(fn (Subscription $s) => $s->isCurrent() && Staff::can(Permission::ManagePayments))
                    ->schema([Textarea::make('reason')->label('السبب')->required()->maxLength(500)])
                    ->action(function (Subscription $record, array $data) {
                        $record->forceFill(['cancelled_at' => now()])->save();
                        Entitlements::forget($record->customer);
                        Audit::log('subscription_cancelled', 'إلغاء اشتراك', $record->customer, ['subscription' => $record->id, 'reason' => $data['reason']]);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSubscriptions::route('/')];
    }
}
