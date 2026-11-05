<?php

namespace App\Http\Controllers\Site;

use App\Actions\Billing\ConfirmPayment;
use App\Actions\Billing\StartCheckout;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Integrations\Payments\PaymentGateway;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Payment;
use App\Services\Billing\Entitlements;
use App\Support\Site\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Buying a plan on the website (the decision of 2 November 2026: the apps sell through the stores, the website
 * through Moyasar). The flow is the app's own billing, unchanged:
 *
 * 1. StartCheckout prices it on the server, checks the plan is for the account's role, and reuses a pending
 *    payment for 30 minutes (two clicks never make two charges).
 * 2. Moyasar's form takes the card on Moyasar's side (our server never sees it). 3-D Secure returns to /return.
 * 3. ConfirmPayment reads the payment back from Moyasar and applies it only if amount, currency and our id match.
 *    The webhook does the same if the browser never comes back.
 *
 * Without Moyasar's keys the payments driver is the explicit simulation, and the page says so: no money moves.
 * A period bought here is not renewed by itself; a reminder goes three days before it ends.
 */
class CheckoutController extends Controller
{
    public function confirm(Package $package): View
    {
        $me = $this->me();
        $plan = Entitlements::for($me);
        $current = $plan->subscription();

        return view('site.checkout.confirm', [
            'package' => $package,
            'fits' => $package->isPurchasable() && $package->audience->value === $me->role->value,
            'customer' => $me,
            'current' => $current,
            'currentPlan' => $plan->package(),
            'store' => in_array($current?->payment?->provider, ['apple', 'google'], true) ? $current->payment->provider : null,
        ]);
    }

    public function start(Package $package, StartCheckout $checkout): RedirectResponse
    {
        try {
            $payment = $checkout($this->me(), PaymentPurpose::Subscription, $package->code, null);
        } catch (ApiException $e) {
            return redirect()->to(Site::url("subscribe/{$package->code}"))->withErrors(['package' => $e->getMessage()]);
        }

        return redirect()->to(Site::url("pay/{$payment->public_id}"));
    }

    public function pay(Payment $payment, PaymentGateway $gateway): View|RedirectResponse|Response
    {
        $this->own($payment);
        if (! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Failed], true)) {
            return redirect()->to(Site::url("pay/{$payment->public_id}/done"));
        }
        $view = ['payment' => $payment->load('target'), 'returnUrl' => Site::url("pay/{$payment->public_id}/return")];
        if ($gateway->name() !== 'moyasar') {
            return view('site.checkout.simulation', $view + ['simulation' => $gateway->clientPayload($payment)]);
        }

        $client = $gateway->clientPayload($payment);
        $methods = (array) config('services.payments.moyasar.web_methods');
        $config = [
            'key' => $client['publishable_key'], 'amount' => $client['amount'], 'currency' => $client['currency'],
            'description' => $client['description'], 'metadata' => $client['metadata'], 'callback' => $view['returnUrl'],
            'methods' => $methods, 'networks' => ['mada', 'visa', 'mastercard'],
            'applePay' => in_array('applepay', $methods, true)
                ? ['country' => 'SA', 'label' => 'زون', 'validate_merchant_url' => 'https://api.moyasar.com/v1/applepay/initiate'] : null,
        ];
        $version = (string) config('services.payments.moyasar.form_version');

        // Moyasar's form: its script and styles from cdn.moyasar.com, its calls to api.moyasar.com; nothing else.
        return response()->view('site.checkout.pay', $view + ['config' => $config, 'version' => $version])->header('Content-Security-Policy',
            "default-src 'self'; script-src 'self' https://cdn.moyasar.com; style-src 'self' 'unsafe-inline' https://cdn.moyasar.com; "
            ."img-src 'self' data: https://cdn.moyasar.com; connect-src 'self' https://api.moyasar.com; frame-src https://api.moyasar.com; "
            ."object-src 'none'; base-uri 'self'; form-action 'self' https://api.moyasar.com; frame-ancestors 'none'");
    }

    /** 3-D Secure (or the simulation) comes back here with the provider's reference; only the provider's answer counts. */
    public function back(Request $request, Payment $payment, ConfirmPayment $confirm): RedirectResponse
    {
        $this->own($payment);
        $reference = $request->query('id');
        if (is_string($reference) && $reference !== '' && strlen($reference) <= 80) {
            $confirm($payment, $reference);
        }

        return redirect()->to(Site::url("pay/{$payment->public_id}/done"));
    }

    public function done(Payment $payment): View
    {
        $this->own($payment);
        $me = $this->me();
        Entitlements::forget($me);

        return view('site.checkout.done', [
            'payment' => $payment->load(['target', 'invoice']),
            'subscription' => Entitlements::for($me)->subscription(),
        ]);
    }

    private function me(): Customer
    {
        $me = Auth::guard('site')->user();
        abort_unless($me instanceof Customer, 403);

        return $me;
    }

    /** Someone else's payment does not exist for this account. */
    private function own(Payment $payment): void
    {
        abort_unless($payment->customer_id === $this->me()->id && in_array($payment->provider, ['moyasar', 'fake'], true), 404);
    }
}
