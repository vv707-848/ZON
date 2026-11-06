<?php

namespace App\Integrations\Stores;

use Illuminate\Http\Request;

/**
 * The App Store or Google Play, asked from our server. The app buys with the store's own sheet and sends us what
 * identifies the purchase (Apple: the transaction id · Google: the purchase token); we read it back from the store
 * and apply it only on the store's word (ApplyStorePurchase).
 */
interface StoreGateway
{
    /**
     * The purchase as the store reports it, or null when the store knows no such purchase.
     *
     * @param  string  $reference  Apple: a transaction id · Google: a purchase token
     */
    public function transaction(string $productId, string $reference, bool $subscription): ?StoreTransaction;

    /** Tell the store the purchase was delivered (Google: acknowledge a subscription, consume a one-time product; Apple: the app finishes it). */
    public function settle(string $productId, string $reference, bool $subscription): void;

    /**
     * A store's server-to-server notice, read as a hint only — the purchase is then read back with transaction().
     * Null when the notice names no purchase we could look up.
     */
    public function notice(Request $request): ?StoreNotice;
}
