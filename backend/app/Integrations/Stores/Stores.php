<?php

namespace App\Integrations\Stores;

use InvalidArgumentException;

/** The two stores, each on its own driver (APP_STORE_DRIVER · GOOGLE_PLAY_DRIVER), so one can go live before the other. */
final class Stores
{
    public const APPLE = 'stores.apple';

    public const GOOGLE = 'stores.google';

    public const NAMES = ['apple', 'google'];

    public static function for(string $store): StoreGateway
    {
        $gateway = match ($store) {
            'apple' => app(self::APPLE),
            'google' => app(self::GOOGLE),
            default => throw new InvalidArgumentException("Unknown store {$store}."),
        };
        assert($gateway instanceof StoreGateway);

        return $gateway;
    }
}
