<?php

namespace App\Filament\Resources\Invoices;

use App\Filament\Concerns\AuthorizesByPermission;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use App\Models\Invoice;
use App\Support\Money;
use App\Support\Permissions\Permission;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** «الفواتير»: simplified tax invoices, numbered without gaps per year (ZATCA phase 1 QR when VAT-registered). */
class InvoiceResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = Invoice::class;

    protected static ?string $modelLabel = 'فاتورة';

    protected static ?string $pluralModelLabel = 'الفواتير';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static ?int $navigationSort = 4;

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
            ->columns([
                TextColumn::make('number')->label('الرقم')->searchable()->copyable(),
                TextColumn::make('buyer_name')->label('المشتري')->searchable(),
                TextColumn::make('issued_at')->label('الإصدار')->dateTime(),
                Columns::money('subtotal_halalas', 'قبل الضريبة'),
                Columns::money('vat_halalas', 'الضريبة'),
                Columns::money('total_halalas', 'الإجمالي'),
            ])
            ->filters([Filters::dateRange('issued_at', 'الإصدار')])
            ->recordActions([
                Action::make('show')->label('عرض')->icon('heroicon-o-eye')->modalSubmitAction(false)
                    ->modalContent(fn (Invoice $record) => new HtmlString(
                        '<dl class="grid grid-cols-2 gap-2 text-sm">'
                        .collect($record->lines)->map(fn (array $l) => '<dt>'.e($l['description']).'</dt><dd>'.(new Money((int) $l['total']))->riyals().' ر.س</dd>')->implode('')
                        .'<dt>نسبة الضريبة</dt><dd>'.e((string) $record->vat_rate).'</dd>'
                        .'<dt>رمز QR (TLV)</dt><dd class="break-all font-mono">'.e($record->qr_tlv ?? 'غير مسجّلة ضريبياً').'</dd></dl>')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListInvoices::route('/')];
    }
}
