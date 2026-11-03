<?php

namespace App\Support;

use App\Enums\PaymentPurpose;
use App\Models\Customer;
use App\Models\Package;

/**
 * What the apps sell through the stores (config zon.store_products): a product id names a purpose and, for a
 * subscription, the plan. The same ids live in App Store Connect and Play Console, where the store sets the price.
 */
final class StoreProducts
{
    /** @return ?array{purpose: PaymentPurpose, package: ?string} */
    public static function find(string $productId): ?array
    {
        $product = ((array) config('zon.store_products'))[$productId] ?? null;     // ids hold dots: not a config path
        if (! is_array($product) || ! is_string($product['purpose'] ?? null)) {
            return null;
        }

        return ['purpose' => PaymentPurpose::from($product['purpose']), 'package' => isset($product['package']) ? (string) $product['package'] : null];
    }

    /**
     * The products this account may buy: its role's plans that are on sale, and the boosts.
     *
     * @return list<array{product_id: string, purpose: string, package: ?string}>
     */
    public static function for(Customer $customer): array
    {
        $plans = Package::query()->where('audience', $customer->role->value)->get()->filter->isPurchasable()->pluck('code')->all();
        $products = [];
        foreach (array_keys((array) config('zon.store_products')) as $id) {
            $product = self::find((string) $id);
            if ($product === null || ($product['purpose'] === PaymentPurpose::Subscription && ! in_array($product['package'], $plans, true))) {
                continue;
            }
            $products[] = ['product_id' => (string) $id, 'purpose' => $product['purpose']->value, 'package' => $product['package']];
        }

        return $products;
    }
}
