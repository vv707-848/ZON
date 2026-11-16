<?php

namespace App\Support\Accounting;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The sales and the payments of a period as CSV files for the accounting software (Daftra): the books are kept there,
 * not here. Days are Riyadh days, as the invoices are dated. Only what moved real money: no simulated payment, no
 * tester's, no store sandbox purchase. Rows in the order they were made (by id). The advertising licence fee is passed on to REGA, so it is marked as not ours.
 * A cell a customer wrote (his name) that a spreadsheet would run as a formula is written as text.
 */
final class Exports
{
    private const TIMEZONE = 'Asia/Riyadh';

    private const MOVED_MONEY = [PaymentStatus::Paid, PaymentStatus::Unapplied, PaymentStatus::Refunded];

    public static function sales(?string $from, ?string $until): StreamedResponse
    {
        $invoices = Invoice::query()->with('payment')
            ->whereHas('payment', fn (Builder $q) => self::real($q))
            ->tap(fn (Builder $q) => self::between($q, 'issued_at', $from, $until));

        return self::csv(self::name('sales', $from, $until), [
            'رقم الفاتورة', 'تاريخ الإصدار', 'اسم العميل', 'رقم الحساب', 'البيان', 'الكمية', 'المبلغ قبل الضريبة', 'الضريبة', 'الإجمالي',
            'العملة', 'النوع المحاسبي', 'حالة الدفعة', 'وسيلة الدفع', 'رقم الدفعة', 'مرجع المزوّد',
        ], function () use ($invoices) {
            foreach ($invoices->lazyById(500) as $invoice) {
                $payment = $invoice->payment;
                yield [
                    $invoice->number, self::day($invoice->issued_at), $invoice->buyer_name, $invoice->customer_id,
                    $payment->purpose->label(), 1, self::riyals($invoice->subtotal_halalas), self::riyals($invoice->vat_halalas),
                    self::riyals($invoice->total_halalas), $payment->currency, self::kind($payment->purpose), $payment->status->label(),
                    $payment->provider, $payment->public_id, $payment->provider_ref,
                ];
            }
        });
    }

    public static function payments(?string $from, ?string $until): StreamedResponse
    {
        $payments = self::real(Payment::query()->with(['customer', 'invoice']))
            ->whereIn('status', self::MOVED_MONEY)
            ->tap(fn (Builder $q) => self::between($q, 'paid_at', $from, $until));

        return self::csv(self::name('payments', $from, $until), [
            'رقم الدفعة', 'تاريخ الدفع', 'اسم العميل', 'رقم الحساب', 'الغرض', 'المبلغ', 'منها الضريبة', 'العملة', 'النوع المحاسبي',
            'الحالة', 'آخر تحديث', 'وسيلة الدفع', 'مرجع المزوّد', 'رقم الفاتورة',
        ], function () use ($payments) {
            foreach ($payments->lazyById(500) as $payment) {
                yield [
                    $payment->public_id, self::day($payment->paid_at), (string) $payment->customer?->name, $payment->customer_id,
                    $payment->purpose->label(), self::riyals($payment->amount_halalas), self::riyals($payment->vat_halalas), $payment->currency,
                    self::kind($payment->purpose), $payment->status->label(), self::day($payment->updated_at), $payment->provider,
                    $payment->provider_ref, $payment->invoice?->number,
                ];
            }
        });
    }

    /**
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    private static function real(Builder $query): Builder
    {
        return $query->where('provider', '!=', 'fake')
            ->whereHas('customer', fn (Builder $q) => $q->where('is_tester', false))
            ->where(fn (Builder $q) => $q->whereNull('meta->test')->orWhere('meta->test', false));
    }

    /** @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query */
    private static function between(Builder $query, string $column, ?string $from, ?string $until): void
    {
        if ($from !== null && $from !== '') {
            $query->where($column, '>=', CarbonImmutable::parse($from, self::TIMEZONE)->startOfDay()->utc());
        }
        if ($until !== null && $until !== '') {
            $query->where($column, '<', CarbonImmutable::parse($until, self::TIMEZONE)->addDay()->startOfDay()->utc());
        }
    }

    private static function kind(PaymentPurpose $purpose): string
    {
        return $purpose === PaymentPurpose::AdLicence ? 'تمرير للهيئة العامة للعقار (ليس إيراداً)' : 'إيراد';
    }

    private static function riyals(int $halalas): string
    {
        return (new Money($halalas))->riyals();
    }

    private static function day(?\DateTimeInterface $at): string
    {
        return $at === null ? '' : CarbonImmutable::instance($at)->setTimezone(self::TIMEZONE)->format('Y-m-d');
    }

    private static function name(string $what, ?string $from, ?string $until): string
    {
        return 'zon-'.$what.'-'.($from ?: 'start').'-to-'.($until ?: CarbonImmutable::now(self::TIMEZONE)->toDateString()).'.csv';
    }

    /**
     * UTF-8 with a byte-order mark, so Excel and Daftra read the Arabic.
     *
     * @param  list<string>  $header
     * @param  callable(): iterable<list<scalar|null>>  $rows
     */
    private static function csv(string $name, array $header, callable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, escape: '');
            foreach ($rows() as $row) {
                fputcsv($out, array_map(self::cell(...), $row), escape: '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** A text a spreadsheet would run (=, +, -, @, a tab or a return first) is written as text. */
    private static function cell(string|int|float|bool|null $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 && ! is_numeric($value) ? "'".$value : $value;
    }
}
