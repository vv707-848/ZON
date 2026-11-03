<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\ApplyStorePurchase;
use App\Http\Controllers\Controller;
use App\Integrations\Stores\Stores;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The stores' server-to-server notices: App Store Server Notifications V2 and Google's real-time developer
 * notifications (Pub/Sub push). The URL carries a key (STORE_NOTICES_KEY) — no key configured, no notice taken.
 * A notice is only a hint: the purchase it names is read back from the store. Any answer but 2xx makes the
 * store send again, so a notice we cannot use is still «received».
 */
class StoreNoticeController extends Controller
{
    public function handle(Request $request, string $store, ApplyStorePurchase $apply): JsonResponse
    {
        $key = (string) config('services.store_notices_key');
        if ($key === '' || ! hash_equals($key, (string) $request->query('key'))) {
            return new JsonResponse(['received' => false], 403);
        }
        $notice = Stores::for($store)->notice($request);
        if ($notice !== null) {
            $apply->notice($store, $notice);
        }

        return new JsonResponse(['received' => true]);
    }
}
