<?php

namespace App\Support;

use App\Models\Customer;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Accounts for the stores' reviewers and for testing in production (customers.is_tester) live in a sandbox. They
 * see the platform as everyone does, and nothing they make or do reaches a real user: not their listings, projects,
 * requests, wishes or profile, not their messages, follows, stars, reports or views. Nor does it reach the AI's
 * training samples, the reports or the daily numbers. Testers see one another, so a flow can be tried end to end.
 *
 * Only the superadmin makes one («حساب مراجعة» in the panel); a real account never becomes one.
 */
final class Sandbox
{
    /** @return list<int> every tester account */
    public static function accounts(): array
    {
        return RequestCache::remember('sandbox:accounts', fn () => Customer::query()->where('is_tester', true)
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all());
    }

    /** A tester was just made: the next question reads the table again. */
    public static function forget(): void
    {
        RequestCache::forget('sandbox:accounts');
    }

    /** What $owner makes is hidden from $viewer (a guest when null): a tester's, from anyone but a tester. */
    public static function hides(?Customer $viewer, ?Customer $owner): bool
    {
        return $owner !== null && $owner->is_tester && ! ($viewer !== null && $viewer->is_tester);
    }

    /** @return list<int> the accounts the sandbox hides from this viewer (a guest when null) */
    public static function hiddenFrom(?Customer $viewer): array
    {
        return $viewer !== null && $viewer->is_tester ? [] : self::accounts();
    }

    /**
     * Who may hear of what $owner did, on a query of customers: anyone — or, for a tester, testers only.
     *
     * @template TQuery of Builder
     *
     * @param  TQuery  $customers
     * @return TQuery
     */
    public static function audience(Builder $customers, Customer $owner, string $column = 'is_tester'): Builder
    {
        if ($owner->is_tester) {
            $customers->where($column, true);
        }

        return $customers;
    }

    /**
     * Rows made by a tester left out of a count; `$column` holds the maker's id, and a row without one (a guest's)
     * stays.
     *
     * @template TQuery of Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function withoutTesters(Builder $query, string $column = 'customer_id'): Builder
    {
        $query->where(fn ($q) => $q->whereNull($column)->orWhereNotIn($column, fn ($testers) => $testers->select('id')->from('customers')->where('is_tester', true)));

        return $query;
    }
}
