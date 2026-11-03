<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\UserBlock;

/**
 * Blocking, applied in the queries: neither side sees the other's content, the blocker's profile answers 404 to
 * the blocked account, and its messages are refused with a general message. The blocked account is never told.
 *
 * What a viewer must not see also includes the sandbox (Sandbox): a tester's content, from anyone but a tester.
 */
final class Blocks
{
    /** @return list<int> accounts whose content this viewer (a guest when null) must not see */
    public static function hiddenFor(?Customer $viewer): array
    {
        $blocked = $viewer === null ? [] : self::blockedWith($viewer);

        return array_values(array_unique([...$blocked, ...Sandbox::hiddenFrom($viewer)]));
    }

    /** Whether what $owner makes is hidden from $viewer (a guest when null). */
    public static function hides(?Customer $viewer, Customer $owner): bool
    {
        return Sandbox::hides($viewer, $owner) || ($viewer !== null && self::between($viewer, $owner));
    }

    /** @return list<int> accounts blocked by this one, or blocking it */
    public static function blockedWith(Customer $customer): array
    {
        return RequestCache::remember("blocks:{$customer->id}", fn () => UserBlock::query()
            ->where('blocker_id', $customer->id)->pluck('blocked_id')
            ->merge(UserBlock::query()->where('blocked_id', $customer->id)->pluck('blocker_id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all());
    }

    /** Either one blocked the other: messages between them are refused. */
    public static function between(Customer $a, Customer $b): bool
    {
        return in_array($b->id, self::blockedWith($a), true);
    }

    public static function forget(Customer ...$customers): void
    {
        foreach ($customers as $customer) {
            RequestCache::forget("blocks:{$customer->id}");
        }
    }
}
