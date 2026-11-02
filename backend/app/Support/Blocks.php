<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\UserBlock;

/**
 * Blocking, applied in the queries: neither side sees the other's content, the blocker's profile answers 404 to
 * the blocked account, and its messages are refused with a general message. The blocked account is never told.
 */
final class Blocks
{
    /** @return list<int> accounts hidden from this viewer (blocked by them, or blocking them) */
    public static function hiddenFor(?Customer $viewer): array
    {
        if ($viewer === null) {
            return [];
        }

        return RequestCache::remember("blocks:{$viewer->id}", fn () => UserBlock::query()
            ->where('blocker_id', $viewer->id)->pluck('blocked_id')
            ->merge(UserBlock::query()->where('blocked_id', $viewer->id)->pluck('blocker_id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all());
    }

    public static function between(Customer $a, Customer $b): bool
    {
        return in_array($b->id, self::hiddenFor($a), true);
    }

    public static function forget(Customer ...$customers): void
    {
        foreach ($customers as $customer) {
            RequestCache::forget("blocks:{$customer->id}");
        }
    }
}
