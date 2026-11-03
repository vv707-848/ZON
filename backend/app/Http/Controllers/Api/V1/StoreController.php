<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\ApplyStorePurchase;
use App\Actions\Billing\PurchaseTarget;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Integrations\Stores\Stores;
use App\Support\Api;
use App\Support\StoreProducts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Buying in the apps, through the store (App Store · Google Play): the products and the account's store token →
 * a check before the store's sheet opens → the purchase, sent once the sheet closed and applied on the store's word.
 */
class StoreController extends Controller
{
    /** The products this account may buy, and the token to attach to each purchase (appAccountToken · obfuscatedAccountId). */
    public function products(Request $request): JsonResponse
    {
        $me = $request->user('api');

        return Api::ok(['account_token' => $me->storeToken(), 'products' => StoreProducts::for($me)]);
    }

    /** Before the store's sheet: can this product be bought now, for this listing or request? The same answers as checkout. */
    public function check(Request $request, PurchaseTarget $targets): JsonResponse
    {
        $data = $this->validated($request, false);
        $product = StoreProducts::find($data['product_id']) ?? throw ApiException::of(ErrorCode::NotFound);
        $targets->resolve($request->user('api'), $product['purpose'], $product['package'], $data['target'] ?? null);

        return Api::ok(['ok' => true, 'account_token' => $request->user('api')->storeToken()]);
    }

    /** After the store's sheet: Apple's transaction id or Google's purchase token. Sent twice, the same payment answers. */
    public function purchase(Request $request, ApplyStorePurchase $apply): JsonResponse
    {
        $data = $this->validated($request, true);
        $payment = $apply($request->user('api'), $data['store'], $data['product_id'], $data['reference'], $data['target'] ?? null);

        return Api::ok(new PaymentResource($payment));
    }

    /** @return array{store: string, product_id: string, reference: string, target?: array{type: string, id: int}} */
    private function validated(Request $request, bool $purchase): array
    {
        /** @var array{store: string, product_id: string, reference: string, target?: array{type: string, id: int}} */
        return $request->validate([
            'store' => [$purchase ? 'required' : 'nullable', Rule::in(Stores::NAMES)],
            'product_id' => ['required', 'string', 'max:100'],
            'reference' => [$purchase ? 'required' : 'nullable', 'string', 'max:4096'],
            'target' => ['nullable', 'array'],
            'target.type' => ['required_with:target', 'string', 'in:listing,request'],
            'target.id' => ['required_with:target', 'integer'],
        ]);
    }
}
