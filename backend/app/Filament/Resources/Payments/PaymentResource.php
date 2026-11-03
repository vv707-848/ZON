<?php

namespace App\Filament\Resources\Payments;

use App\Actions\Admin\RefundPayment;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use App\Filament\Support\Staff;
use App\Integrations\Stores\Stores;
use App\Models\Payment;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * «المدفوعات والاسترداد»: every payment as the provider confirmed it. «غير مطبّقة» = paid but its target could no
 * longer take it (refund those). A simulated payment (provider «fake») moved no money.
 */
class PaymentResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = Payment::class;

    protected static ?string $modelLabel = 'دفعة';

    protected static ?string $pluralModelLabel = 'المدفوعات والاسترداد';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 3;

    protected static function viewPermission(): Permission
    {
        return Permission::ViewPayments;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('الدفعة')->columns(4)->schema([
                TextEntry::make('public_id')->label('رقم الدفعة')->copyable(),
                Columns::enumEntry('purpose', 'الغرض'),
                Columns::enumEntry('status', 'الحالة'),
                Columns::moneyEntry('amount_halalas', 'المبلغ'),
                Columns::moneyEntry('vat_halalas', 'منها الضريبة'),
                TextEntry::make('provider')->label('المزوّد')->formatStateUsing(fn (string $state) => $state === 'fake' ? 'محاكاة — لم يُدفع مال' : $state),
                TextEntry::make('provider_ref')->label('مرجع المزوّد')->placeholder('—')->copyable(),
                TextEntry::make('failure_reason')->label('سبب الفشل')->placeholder('—'),
                TextEntry::make('customer.name')->label('الحساب')->url(fn (Payment $p) => CustomerResource::getUrl('view', ['record' => $p->customer_id])),
                TextEntry::make('target_type')->label('مقابل')->formatStateUsing(fn (?string $state, Payment $p) => "{$state} #{$p->target_id}"),
                TextEntry::make('created_at')->label('الإنشاء')->dateTime(),
                TextEntry::make('paid_at')->label('الدفع')->dateTime()->placeholder('—'),
                TextEntry::make('invoice.number')->label('الفاتورة')->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('customer'))
            ->columns([
                TextColumn::make('public_id')->label('رقم الدفعة')->searchable()->copyable()->limit(12),
                TextColumn::make('customer.name')->label('الحساب')->searchable()->limit(20),
                Columns::enum('purpose', 'الغرض'),
                Columns::money('amount_halalas', 'المبلغ'),
                Columns::enum('status', 'الحالة', Columns::STATUS_COLORS),
                TextColumn::make('provider')->label('المزوّد')->badge()->color(fn (string $state) => $state === 'fake' ? 'warning' : 'gray'),
                TextColumn::make('paid_at')->label('الدفع')->dateTime()->sortable()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(PaymentStatus::options()),
                SelectFilter::make('purpose')->label('الغرض')->options(PaymentPurpose::options()),
                Filters::dateRange('created_at', 'التاريخ'),
            ])
            ->recordActions([ViewAction::make(), self::refundAction()]);
    }

    public static function refundAction(): Action
    {
        return Action::make('refund')->label('استرداد')->color('danger')->icon('heroicon-o-receipt-refund')
            ->visible(fn (Payment $p) => in_array($p->status, [PaymentStatus::Paid, PaymentStatus::Unapplied], true) && ! in_array($p->provider, Stores::NAMES, true) && Staff::can(Permission::ManagePayments))
            ->schema([Textarea::make('reason')->label('السبب (يُسجَّل)')->required()->maxLength(500)])
            ->requiresConfirmation()
            ->action(function (Payment $record, array $data) {
                app(RefundPayment::class)($record, $data['reason']);
                Notification::make()->title('استُردّت الدفعة وأُوقف ما اشتُري بها')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListPayments::route('/'), 'view' => ViewPayment::route('/{record}')];
    }
}
