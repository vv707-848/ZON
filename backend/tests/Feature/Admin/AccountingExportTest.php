<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Permissions\Permission;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * «تصدير لدفترة»: the books are kept in Daftra (decision of 17 November), so the panel hands over a period's sales and
 * payments as CSV files: Riyadh days, real money only, the REGA fee marked as not ours, a customer's text never a
 * formula, and every export in the audit log.
 */
class AccountingExportTest extends AdminTestCase
{
    /** @param  array<string, mixed>  $payment */
    private function sale(Customer $buyer, string $paidAt, array $payment = [], bool $invoice = true): Payment
    {
        $row = Payment::query()->create($payment + ['customer_id' => $buyer->id, 'purpose' => 'subscription', 'amount_halalas' => 19_900,
            'provider' => 'moyasar', 'provider_ref' => 'pay_'.uniqid(), 'status' => 'paid', 'paid_at' => Carbon::parse($paidAt, 'UTC')]);
        if ($invoice) {
            Invoice::query()->create(['customer_id' => $buyer->id, 'payment_id' => $row->id, 'number' => sprintf('ZON-2026-%06d', $row->id),
                'issued_at' => $row->paid_at, 'subtotal_halalas' => $row->amount_halalas, 'vat_halalas' => 0, 'total_halalas' => $row->amount_halalas,
                'vat_rate' => '0', 'buyer_name' => $buyer->name, 'lines' => []]);
        }

        return $row;
    }

    /** @return list<list<string>> */
    private function rows(string $page, string $action): array
    {
        $name = 'zon-'.($action === 'export_sales' ? 'sales' : 'payments').'-2026-11-11-to-2026-11-11.csv';
        $download = Livewire::test($page)->callAction($action, ['from' => '2026-11-11', 'until' => '2026-11-11'])
            ->assertHasNoActionErrors()->assertFileDownloaded($name)->__get('effects')['download'];
        $csv = base64_decode($download['content']);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'with a byte-order mark: Excel and Daftra read the Arabic');

        return array_map(fn (string $line) => str_getcsv($line, escape: ''), array_values(array_filter(explode("\n", substr($csv, 3)))));
    }

    public function test_a_days_real_sales_and_payments_go_out_for_the_accounts(): void
    {
        $buyer = Customer::factory()->seeker()->create(['name' => '=HYPERLINK("http://x","اضغط")']);
        $late = $this->sale($buyer, '2026-11-10 22:30:00');                       // 01:30 on the 11th in Riyadh: in
        $this->sale($buyer, '2026-11-10 20:00:00');                                // 23:00 on the 10th in Riyadh: out
        $this->sale($buyer, '2026-11-11 12:00:00', ['purpose' => 'ad_licence', 'amount_halalas' => 5_000]);
        $refunded = $this->sale($buyer, '2026-11-11 13:00:00', ['status' => 'refunded']);
        $this->sale($buyer, '2026-11-11 14:00:00', ['provider' => 'fake', 'provider_ref' => null]);
        $this->sale(Customer::factory()->seeker()->create(['is_tester' => true]), '2026-11-11 15:00:00');
        $this->sale($buyer, '2026-11-11 16:00:00', ['provider' => 'apple', 'meta' => ['test' => true]], invoice: false);
        $this->sale($buyer, '2026-11-11 17:00:00', ['status' => 'failed', 'paid_at' => null], invoice: false);
        $this->sale($buyer, '2026-11-11 18:00:00', ['provider' => 'apple'], invoice: false);
        $staff = $this->signInStaff(Permission::ViewPayments);

        $sales = $this->rows(ListInvoices::class, 'export_sales');
        $header = array_shift($sales);
        $this->assertSame(['رقم الفاتورة', 'تاريخ الإصدار', 'اسم العميل'], array_slice($header, 0, 3));
        $this->assertSame([$late->invoice->number, '2026-11-11', "'".$buyer->name, '199.00', '0.00', '199.00', 'إيراد'],
            [$sales[0][0], $sales[0][1], $sales[0][2], $sales[0][6], $sales[0][7], $sales[0][8], $sales[0][10]]);
        $this->assertSame(['تمرير للهيئة العامة للعقار (ليس إيراداً)', 'مستردة'], [$sales[1][10], $sales[2][11]]);
        $this->assertCount(3, $sales, 'no simulation, tester, sandbox, other day; no invoice for an Apple sale');

        $payments = array_slice($this->rows(ListPayments::class, 'export_payments'), 1);
        $this->assertSame([$late->public_id, $refunded->public_id], [$payments[0][0], $payments[2][0]]);
        $this->assertSame(['apple', ''], [$payments[3][11], $payments[3][13]], 'an Apple sale: received, with no invoice of ours');
        $this->assertCount(4, $payments, 'and no failed payment');

        $this->assertSame(['export_sales', 'export_payments'], Activity::query()->where('causer_id', $staff->id)->orderBy('id')->pluck('event')->all());
    }

    public function test_without_the_finance_permission_there_is_no_export(): void
    {
        $this->signInStaff(Permission::ViewListings);
        $this->get(ListPayments::getUrl())->assertForbidden();
        $this->get(ListInvoices::getUrl())->assertForbidden();
    }
}
