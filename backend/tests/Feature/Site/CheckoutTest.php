<?php

namespace Tests\Feature\Site;

use App\Enums\PaymentStatus;
use App\Integrations\Payments\MoyasarGateway;
use App\Integrations\Payments\PaymentGateway;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Subscription;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Http\Client\Request as Sent;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Buying a plan on the website: the app's own billing (server prices, the provider's answer only), Moyasar's form
 * where the keys are set and an explicit simulation where they are not, the result, and the invoice.
 */
class CheckoutTest extends TestCase
{
    private function site(string $path = ''): string
    {
        return 'https://zon.sa/'.ltrim($path, '/');
    }

    private function seeker(): Customer
    {
        $seeker = Customer::factory()->seeker()->create(['username' => 'mohammed', 'password' => 'a-good-secret']);
        $this->post($this->site('login'), ['login' => 'mohammed', 'password' => 'a-good-secret']);

        return $seeker;
    }

    public function test_the_plans_page_shows_the_apps_plans_and_prices(): void
    {
        $seeker = Package::query()->where('code', Package::SEEKER)->firstOrFail();

        $this->get($this->site('packages'))->assertOk()->assertSee($seeker->name)->assertSee('199 ريال')->assertSee('مساعد المطابقة يومياً')
            ->assertSee('https://zon.sa/subscribe/'.$seeker->code, false)->assertSee('بلا تجديد تلقائي')->assertSee('50 ريال');
        $this->get($this->site('subscribe/'.$seeker->code))->assertRedirect('https://zon.sa/login');
    }

    public function test_a_plan_is_bought_in_the_simulation_which_says_so_and_the_invoice_follows(): void
    {
        $me = $this->seeker();
        $this->get($this->site('subscribe/broker-month'))->assertOk()->assertSee('هذه الباقة لـ');

        $this->get($this->site('subscribe/'.Package::SEEKER))->assertOk()->assertSee('متابعة إلى الدفع');
        $pay = $this->post($this->site('subscribe/'.Package::SEEKER));
        $payment = Payment::query()->sole();
        $pay->assertRedirect('https://zon.sa/pay/'.$payment->public_id);
        $this->assertSame([19_900, 'fake', PaymentStatus::Pending], [$payment->amount_halalas, $payment->provider, $payment->status]);
        $this->post($this->site('subscribe/'.Package::SEEKER));
        $this->assertSame(1, Payment::query()->count(), 'a second click reuses the pending payment');

        $page = $this->get($this->site('pay/'.$payment->public_id))->assertOk()->assertSee('محاكاة: لا تتحرك أي أموال');

        // The declined card first, then the retry.
        $this->get($this->site("pay/{$payment->public_id}/return?id=fake_{$payment->public_id}_fail"))->assertRedirect($this->site("pay/{$payment->public_id}/done"));
        $this->get($this->site("pay/{$payment->public_id}/done"))->assertSee('لم يكتمل الدفع')->assertSee('حاول مرة أخرى');
        $this->get($this->site("pay/{$payment->public_id}/return?id=fake_{$payment->public_id}"));

        $done = $this->get($this->site("pay/{$payment->public_id}/done"))->assertOk()->assertSee('تم الدفع')->assertSee('سارية حتى');
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertTrue(Subscription::query()->where('customer_id', $me->id)->current()->exists());
        $invoice = Invoice::query()->sole();
        $done->assertSee('الإيصال');
        $this->get($this->site('account/invoices'))->assertOk()->assertSee($invoice->number);
        $this->get($this->site('account/invoices/'.$invoice->id))->assertOk()->assertSee('إيصال دفع')->assertSee('199 ريال')->assertDontSee('رمز الفاتورة');
        $this->get($this->site('pay/'.$payment->public_id))->assertRedirect($this->site("pay/{$payment->public_id}/done"));
    }

    public function test_someone_elses_payment_or_invoice_does_not_exist(): void
    {
        $other = Customer::factory()->seeker()->create();
        $payment = Payment::query()->create(['customer_id' => $other->id, 'purpose' => 'subscription', 'amount_halalas' => 19_900, 'provider' => 'fake', 'status' => 'pending']);
        $invoice = Invoice::query()->create(['customer_id' => $other->id, 'payment_id' => $payment->id, 'number' => 'ZON-2026-000099', 'issued_at' => now(),
            'subtotal_halalas' => 19_900, 'vat_halalas' => 0, 'total_halalas' => 19_900, 'vat_rate' => '0', 'buyer_name' => 'غيري', 'lines' => []]);
        $this->seeker();

        $this->get($this->site('pay/'.$payment->public_id))->assertNotFound();
        $this->get($this->site("pay/{$payment->public_id}/return?id=fake_{$payment->public_id}"))->assertNotFound();
        $this->get($this->site('account/invoices/'.$invoice->id))->assertNotFound();
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_with_moyasars_keys_the_card_goes_to_moyasars_form_and_only_moyasars_answer_counts(): void
    {
        app()->instance(PaymentGateway::class, new MoyasarGateway('sk_test_secret', 'pk_test_public', 'whsec', 'https://api.moyasar.com/v1'));
        Settings::put(Setting::VatRegistered, true);
        Settings::put(Setting::VatNumber, '300000000000003');
        $this->seeker();
        $this->post($this->site('subscribe/'.Package::SEEKER));
        $payment = Payment::query()->sole();

        $page = $this->get($this->site('pay/'.$payment->public_id))->assertOk()
            ->assertSee('https://cdn.moyasar.com/mpf/1.15.0/moyasar.js', false)->assertSee('"key":"pk_test_public"', false)
            ->assertSee('"amount":19900', false)->assertSee('"callback":"https://zon.sa/pay/'.$payment->public_id.'/return"', false)
            ->assertDontSee('sk_test_secret');
        $this->assertStringContainsString('script-src \'self\' https://cdn.moyasar.com', (string) $page->headers->get('Content-Security-Policy'));

        Http::fake(fn (Sent $r) => str_starts_with($r->url(), 'https://api.moyasar.com/v1/payments/') ? Http::response([
            'id' => 'pay_moyasar_1', 'status' => 'paid', 'amount' => 19_900, 'currency' => 'SAR', 'metadata' => ['payment_id' => $payment->public_id],
        ]) : null);
        $this->get($this->site("pay/{$payment->public_id}/return?id=pay_moyasar_1&status=paid&message=APPROVED"));

        $this->assertSame([PaymentStatus::Paid, 'pay_moyasar_1'], [$payment->fresh()->status, $payment->fresh()->provider_ref]);
        Http::assertSent(fn (Sent $r) => $r->url() === 'https://api.moyasar.com/v1/payments/pay_moyasar_1' && $r->hasHeader('Authorization'));
        $invoice = Invoice::query()->sole();
        $this->get($this->site('account/invoices/'.$invoice->id))->assertOk()->assertSee('فاتورة ضريبية مبسطة')->assertSee('300000000000003')
            ->assertSee('aria-label="رمز الفاتورة لهيئة الزكاة والضريبة والجمارك"', false);
    }

    public function test_apple_pays_domain_file_is_served_once_moyasar_gives_it(): void
    {
        $this->get($this->site('.well-known/apple-developer-merchantid-domain-association'))->assertNotFound();

        $file = tempnam(sys_get_temp_dir(), 'apple');
        file_put_contents($file, 'MIIQ-domain-association');
        config(['services.payments.moyasar.apple_pay_domain_file' => $file]);

        $this->get($this->site('.well-known/apple-developer-merchantid-domain-association'))->assertOk()->assertSee('MIIQ-domain-association');
    }
}
