<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\ApplyBoost;
use App\Actions\Billing\ConfirmPayment;
use App\Actions\Billing\StartCheckout;
use App\Enums\BoostKind;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Integrations\Payments\PaymentGateway;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Payment;
use App\Services\Billing\Entitlements;
use App\Support\Api;
use App\Support\Money;
use App\Support\Ownership;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Plans, the account's subscription and balances, checkout → the provider's sheet → confirm, invoices. */
class BillingController extends Controller
{
    public function packages(): JsonResponse
    {
        return Api::ok([
            'plans' => Package::query()->where('is_active', true)->orderBy('sort')->get()->map(fn (Package $p) => [
                'code' => $p->code, 'audience' => $p->audience->value, 'name' => $p->name, 'period' => $p->period->value,
                'price' => $p->price()->riyals(), 'purchasable' => $p->isPurchasable(), 'features' => $p->features ?? [],
                'quotas' => ['featured' => $p->featured_quota, 'urgent_listing' => $p->urgent_listing_quota, 'urgent_request' => $p->urgent_request_quota],
            ]),
            'one_off' => [
                'featured' => ApplyBoost::price(BoostKind::Featured)->riyals(),
                'urgent_listing' => ApplyBoost::price(BoostKind::UrgentListing)->riyals(),
                'urgent_request' => ApplyBoost::price(BoostKind::UrgentRequest)->riyals(),
                'ad_licence' => Settings::money(Setting::AdLicenceFee)->riyals(),
                'boost_days' => Settings::int(Setting::BoostDays),
            ],
            'owner_commission_percent' => Settings::string(Setting::OwnerCommissionPercent),
            'broker_free_share_percent' => Settings::string(Setting::BrokerFreeSharePercent),
            'vat' => ['registered' => Settings::bool(Setting::VatRegistered), 'rate' => Settings::string(Setting::VatRate)],
        ]);
    }

    public function subscription(Request $request): JsonResponse
    {
        $plan = Entitlements::for($request->user('api'));
        $package = $plan->package();

        return Api::ok([
            'plan' => ['code' => $package->code, 'name' => $package->name, 'subscribed' => $plan->isSubscribed()],
            'starts_at' => $plan->subscription()?->starts_at->toIso8601String(),
            'ends_at' => $plan->subscription()?->ends_at?->toIso8601String(),
            'balances' => $plan->balances(),
            'limits' => [
                'listings' => $package->listing_limit, 'inventory' => $package->inventory_limit, 'preferences' => $plan->preferencesLimit(),
                'area_requests' => $plan->areaRequests()->value, 'search_requests' => $plan->canSearchRequests(),
                'ai_daily' => ['search' => $plan->aiDaily('search'), 'matching' => $plan->aiDaily('matching'), 'summary' => $plan->aiDaily('summary'), 'chat' => $plan->aiDaily('chat')],
            ],
        ]);
    }

    public function checkout(Request $request, StartCheckout $checkout, PaymentGateway $gateway): JsonResponse
    {
        $data = $request->validate([
            'purpose' => ['required', Rule::enum(PaymentPurpose::class)],
            'package' => ['required_if:purpose,subscription', 'nullable', 'string'],
            'target' => ['required_unless:purpose,subscription', 'nullable', 'array'],
            'target.type' => ['required_with:target', 'string'],
            'target.id' => ['required_with:target', 'integer'],
        ]);
        $payment = $checkout($request->user('api'), PaymentPurpose::from($data['purpose']), $data['package'] ?? null, $data['target'] ?? null);

        return Api::created($this->present($payment) + ['client' => $gateway->clientPayload($payment)]);
    }

    public function payment(Request $request, Payment $payment): JsonResponse
    {
        Ownership::assert($payment, $request->user('api'));

        return Api::ok($this->present($payment));
    }

    /** The app calls this after the provider's sheet closed; only the provider's own answer counts. */
    public function confirm(Request $request, Payment $payment, ConfirmPayment $confirm): JsonResponse
    {
        Ownership::assert($payment, $request->user('api'));
        $data = $request->validate(['reference' => ['required', 'string', 'max:80']]);
        $payment = $confirm($payment, $data['reference']);

        return Api::ok($this->present($payment), status: $payment->status === PaymentStatus::Pending ? 202 : 200);
    }

    public function invoices(Request $request): JsonResponse
    {
        $page = Invoice::query()->where('customer_id', $request->user('api')->id)->latest('issued_at')->paginate(20);

        return Api::ok($page->getCollection()->map(fn (Invoice $i) => $this->invoiceRow($i)), ['page' => ['current' => $page->currentPage(), 'last' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function invoice(Request $request, Invoice $invoice): JsonResponse
    {
        Ownership::assert($invoice, $request->user('api'));

        return Api::ok($this->invoiceRow($invoice) + ['lines' => $invoice->lines, 'qr' => $invoice->qr_tlv,
            'seller' => ['name' => Settings::string(Setting::SellerName), 'vat_number' => Settings::string(Setting::VatNumber) ?: null]]);
    }

    /** @return array<string, mixed> */
    private function present(Payment $payment): array
    {
        return (new PaymentResource($payment))->resolve();
    }

    /** @return array<string, mixed> */
    private function invoiceRow(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'issued_at' => $invoice->issued_at->toIso8601String(),
            'subtotal' => (new Money($invoice->subtotal_halalas))->riyals(),
            'vat' => (new Money($invoice->vat_halalas))->riyals(),
            'total' => (new Money($invoice->total_halalas))->riyals(),
        ];
    }
}
