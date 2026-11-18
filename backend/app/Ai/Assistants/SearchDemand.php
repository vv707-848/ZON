<?php

namespace App\Ai\Assistants;

use App\Ai\Retrieval\Need;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * The demand nobody posted: what a semantic search asked for, for the market reports (search_demand). Only the
 * criteria — city, neighbourhoods, types, buy or rent, the top of the budget, rooms, the area said — never the text,
 * the account or the device. `searcher` is a keyed hash of the account that changes each month: the reports count
 * people, not clicks, and it leads back to no one. A tester's search is no demand.
 */
final class SearchDemand
{
    public static function record(Need $need, Customer $customer): void
    {
        if ($customer->is_tester || $need->purpose === null) {
            return;
        }
        DB::table('search_demand')->insert([
            'purpose' => $need->purpose,
            'city' => $need->city,
            'neighborhoods' => json_encode(array_values($need->neighborhoods), JSON_UNESCAPED_UNICODE),
            'property_types' => json_encode(array_values($need->types)),
            'budget_max' => $need->budgetMax,
            'bedrooms' => $need->bedrooms === null ? null : min(255, $need->bedrooms),
            'area' => $need->area,
            'searcher' => self::searcher($customer),
            'created_at' => now(),
        ]);
    }

    public static function searcher(Customer $customer): string
    {
        return substr(hash_hmac('sha256', now('Asia/Riyadh')->format('Y-m').'|'.$customer->id, (string) config('app.key')), 0, 16);
    }
}
