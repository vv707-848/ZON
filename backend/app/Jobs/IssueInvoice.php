<?php

namespace App\Jobs;

use App\Enums\PaymentStatus;
use App\Integrations\Invoicing\InvoicingGateway;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/** A simplified tax invoice per paid payment: numbered without gaps per year under a row lock (invoice_counters). */
class IssueInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly int $paymentId) {}

    public function handle(InvoicingGateway $invoicing): void
    {
        $payment = Payment::query()->with('customer')->find($this->paymentId);
        if ($payment === null || ! in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::Unapplied], true) || $payment->invoice()->exists()) {
            return;
        }

        DB::transaction(function () use ($payment, $invoicing) {
            $year = (int) $payment->paid_at?->year ?: now()->year;
            DB::table('invoice_counters')->insertOrIgnore(['year' => $year, 'last_number' => 0]);
            $counter = DB::table('invoice_counters')->where('year', $year)->lockForUpdate()->first();
            $next = (int) $counter->last_number + 1;
            DB::table('invoice_counters')->where('year', $year)->update(['last_number' => $next]);

            $subtotal = $payment->amount_halalas - $payment->vat_halalas;
            $invoice = Invoice::query()->create([
                'payment_id' => $payment->id,
                'customer_id' => $payment->customer_id,
                'number' => sprintf('ZON-%d-%06d', $year, $next),
                'issued_at' => $payment->paid_at ?? now(),
                'subtotal_halalas' => $subtotal,
                'vat_halalas' => $payment->vat_halalas,
                'total_halalas' => $payment->amount_halalas,
                'vat_rate' => $payment->vat_halalas > 0 ? Settings::string(Setting::VatRate) : '0',
                'buyer_name' => $payment->customer->name,
                'lines' => [['description' => $payment->purpose->label(), 'quantity' => 1, 'subtotal' => $subtotal, 'vat' => $payment->vat_halalas, 'total' => $payment->amount_halalas]],
            ]);
            if (Settings::bool(Setting::VatRegistered)) {
                $invoice->forceFill(['qr_tlv' => $invoicing->qr($invoice, Settings::string(Setting::SellerName), Settings::string(Setting::VatNumber))])->save();
            }
        });
    }
}
