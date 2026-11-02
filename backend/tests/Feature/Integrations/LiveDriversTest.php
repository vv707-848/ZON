<?php

namespace Tests\Feature\Integrations;

use App\Integrations\IntegrationException;
use App\Integrations\Payments\FakePaymentGateway;
use App\Integrations\Payments\GatewayPayment;
use App\Integrations\Payments\MoyasarGateway;
use App\Integrations\Payments\PaymentGateway;
use App\Integrations\Push\FcmPushGateway;
use App\Integrations\Rega\RegaGateway;
use App\Integrations\Sms\TaqnyatSmsGateway;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The live drivers, against answers shaped like their providers' documentation, and the
 * switch between drivers (config/services.php). Going live still needs a check with the
 * real account (docs/integrations.md) — these tests keep the code honest until then.
 */
class LiveDriversTest extends TestCase
{
    public function test_one_env_value_switches_a_service_and_production_refuses_unlisted_simulations(): void
    {
        config(['services.payments.driver' => 'moyasar']);
        $this->app->forgetInstance(PaymentGateway::class);
        $this->assertInstanceOf(MoyasarGateway::class, app(PaymentGateway::class));

        $this->app['env'] = 'production';
        config(['services.payments.driver' => 'fake', 'services.production_allows_fake' => 'rega, nafath']);
        $this->app->forgetInstance(PaymentGateway::class);
        $this->assertInstanceOf(RegaGateway::class, app(RegaGateway::class), 'listed: allowed');
        try {
            app(PaymentGateway::class);
            $this->fail('a simulated payment driver ran in production');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not allowed in production', $e->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_taqnyat_gets_the_number_without_plus_and_a_refusal_is_an_integration_failure(): void
    {
        Http::fake(['api.taqnyat.sa/*' => Http::sequence()->push(['statusCode' => 201], 201)->push(['message' => 'sender not approved'], 400)]);
        $sms = new TaqnyatSmsGateway('tq-token', 'ZONE');

        $sms->send('+966551234567', 'رمز التحقق 1234');

        Http::assertSent(fn (SentRequest $r) => $r->url() === 'https://api.taqnyat.sa/v1/messages'
            && $r->hasHeader('Authorization', 'Bearer tq-token')
            && $r['recipients'] === ['966551234567'] && $r['sender'] === 'ZONE');
        $this->expectException(IntegrationException::class);
        $sms->send('+966551234567', 'رمز التحقق 1234');
    }

    public function test_moyasar_payments_are_read_with_the_secret_key_and_webhooks_need_the_shared_token(): void
    {
        Http::fake(['api.moyasar.com/v1/payments/pay_1' => Http::response([
            'id' => 'pay_1', 'status' => 'paid', 'amount' => 34900, 'currency' => 'SAR', 'metadata' => ['payment_id' => 'PT-1'],
        ])]);
        $moyasar = new MoyasarGateway('sk_test', 'pk_test', 'wh-secret');

        $payment = $moyasar->fetch('pay_1');

        $this->assertEquals([GatewayPayment::PAID, 34900, 'SAR', 'PT-1'], [$payment->status, $payment->amountHalalas, $payment->currency, $payment->paymentPublicId]);
        Http::assertSent(fn (SentRequest $r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('sk_test:')));
        $this->assertSame('pay_1', $moyasar->webhookReference(Request::create('/', 'POST', ['secret_token' => 'wh-secret', 'data' => ['id' => 'pay_1']])));
        $this->assertNull($moyasar->webhookReference(Request::create('/', 'POST', ['secret_token' => 'guess', 'data' => ['id' => 'pay_1']])));
    }

    public function test_fcm_signs_in_with_the_service_account_and_returns_dead_tokens(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        $credentials = tempnam(sys_get_temp_dir(), 'fcm');
        file_put_contents($credentials, json_encode(['project_id' => 'zon-app', 'client_email' => 'push@zon-app.iam.gserviceaccount.com', 'private_key' => $privateKey]));
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.token', 'expires_in' => 3599]),
            'fcm.googleapis.com/*' => Http::sequence()
                ->push(['name' => 'projects/zon-app/messages/1'])
                ->push(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
        ]);

        $dead = (new FcmPushGateway($credentials))->send(['token-alive', 'token-gone'], 'عرض جديد', 'في حي النرجس', ['listing_id' => 12]);

        $this->assertSame(['token-gone'], $dead);
        Http::assertSent(fn (SentRequest $r) => $r->url() === 'https://fcm.googleapis.com/v1/projects/zon-app/messages:send'
            && $r->hasHeader('Authorization', 'Bearer ya29.token') && $r['message']['data'] === ['listing_id' => '12']);
        unlink($credentials);
    }

    public function test_the_fake_payment_driver_says_it_is_a_simulation(): void
    {
        $this->assertSame('fake', app(PaymentGateway::class)->name());
        $this->assertInstanceOf(FakePaymentGateway::class, app(PaymentGateway::class));
    }
}
