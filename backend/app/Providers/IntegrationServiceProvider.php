<?php

namespace App\Providers;

use App\Integrations\Drivers;
use App\Integrations\ImageHash\AgentsImageHasher;
use App\Integrations\ImageHash\GdImageHasher;
use App\Integrations\ImageHash\ImageHasher;
use App\Integrations\Invoicing\InvoicingGateway;
use App\Integrations\Invoicing\LocalInvoicing;
use App\Integrations\Nafath\FakeNafathGateway;
use App\Integrations\Nafath\NafathGateway;
use App\Integrations\Payments\FakePaymentGateway;
use App\Integrations\Payments\MoyasarGateway;
use App\Integrations\Payments\PaymentGateway;
use App\Integrations\Push\FakePushGateway;
use App\Integrations\Push\FcmPushGateway;
use App\Integrations\Push\PushGateway;
use App\Integrations\Rega\FakeRegaGateway;
use App\Integrations\Rega\RegaGateway;
use App\Integrations\Sms\FakeSmsGateway;
use App\Integrations\Sms\SmsGateway;
use App\Integrations\Sms\TaqnyatSmsGateway;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * One line per driver: the service, the driver name in config/services.php, the class built for it.
 * To go live with a provider: write its class, add its line here, set the driver in .env.
 */
class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->driver(SmsGateway::class, 'sms', [
            'fake' => fn () => new FakeSmsGateway,
            'taqnyat' => fn () => new TaqnyatSmsGateway((string) config('services.sms.taqnyat.token'), (string) config('services.sms.taqnyat.sender'), (string) config('services.sms.taqnyat.url')),
        ]);
        $this->driver(RegaGateway::class, 'rega', ['fake' => fn () => new FakeRegaGateway]);
        $this->driver(NafathGateway::class, 'nafath', ['fake' => fn () => new FakeNafathGateway]);
        $this->driver(PaymentGateway::class, 'payments', [
            'fake' => fn () => new FakePaymentGateway,
            'moyasar' => fn () => new MoyasarGateway(
                (string) config('services.payments.moyasar.secret_key'),
                (string) config('services.payments.moyasar.publishable_key'),
                (string) config('services.payments.moyasar.webhook_secret'),
                (string) config('services.payments.moyasar.url'),
            ),
        ]);
        $this->driver(PushGateway::class, 'push', [
            'fake' => fn () => new FakePushGateway,
            'fcm' => fn () => new FcmPushGateway((string) config('services.push.fcm.credentials')),
        ]);
        $this->driver(InvoicingGateway::class, 'invoicing', ['local' => fn () => new LocalInvoicing]);
        $this->driver(ImageHasher::class, 'image_hash', ['gd' => fn () => new GdImageHasher, 'agents' => fn () => new AgentsImageHasher]);
    }

    /** @param array<string, \Closure(): object> $drivers */
    private function driver(string $contract, string $service, array $drivers): void
    {
        $this->app->singleton($contract, function () use ($service, $drivers) {
            $driver = Drivers::of($service);
            if (! isset($drivers[$driver])) {
                throw new RuntimeException("services.{$service}.driver: unknown driver \"{$driver}\"");
            }
            if ($driver === Drivers::FAKE && $this->app->isProduction() && ! Drivers::fakeAllowedInProduction($service)) {
                throw new RuntimeException("The simulated {$service} driver is not allowed in production (PRODUCTION_ALLOWS_FAKE).");
            }

            return $drivers[$driver]();
        });
    }
}
