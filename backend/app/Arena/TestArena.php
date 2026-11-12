<?php

namespace App\Arena;

use App\Enums\AccountStatus;
use App\Enums\CustomerRole;
use App\Enums\FalStatus;
use App\Enums\LicenceSource;
use App\Enums\LicenceStatus;
use App\Enums\ListingStatus;
use App\Enums\RequestStatus;
use App\Enums\TextReview;
use App\Filament\Support\Audit;
use App\Indexing\IndexSync;
use App\Models\AdLicense;
use App\Models\ArenaBatch;
use App\Models\BuyerPreference;
use App\Models\City;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\SearchRequest;
use App\Models\User;
use App\Support\Sandbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * «ساحة الاختبار»: the superadmin fills a city with sample listings, requests and wishes (ArenaSamples), to try
 * search, matching and the AI end to end — in production too, before the first real user. They belong to the
 * batch's own accounts, which live in the sandbox (App\Support\Sandbox): no real user, report or daily number ever
 * sees them; a review account sees them as a real user would. The accounts cannot sign in (no password anyone
 * knows, no real phone: +999 is no country). Listings are published with an arena licence that ends in 30 days,
 * and go to the index like any other. One button removes the batch: every row, and every point in the index.
 */
final class TestArena
{
    public const MAX_LISTINGS = 500;

    public const MAX_REQUESTS = 200;

    public const MAX_WISHES = 200;

    /** Listings are spread over this many brokers, so «more from this advertiser» has something to show. */
    private const BROKERS = 5;

    public function __construct(private readonly IndexSync $index) {}

    /**
     * @param  list<string>  $neighborhoods  empty: all of the city's
     */
    public function generate(string $city, array $neighborhoods, int $listings, int $requests, int $wishes, int $rentPercent, User $by): ArenaBatch
    {
        $known = City::catalog()[$city] ?? null;
        $neighborhoods = $neighborhoods === [] ? ($known ?? []) : array_values(array_intersect($neighborhoods, $known ?? []));
        if ($known === null || $neighborhoods === []) {
            throw new InvalidArgumentException('اختر مدينة من القائمة وأحياءً منها.');
        }
        if ($listings < 0 || $listings > self::MAX_LISTINGS || $requests < 0 || $requests > self::MAX_REQUESTS
            || $wishes < 0 || $wishes > self::MAX_WISHES || $listings + $requests + $wishes === 0 || $rentPercent < 0 || $rentPercent > 100) {
            throw new InvalidArgumentException('الأعداد خارج الحدود.');
        }

        $batch = DB::transaction(function () use ($city, $neighborhoods, $listings, $requests, $wishes, $rentPercent, $by) {
            $batch = ArenaBatch::query()->create([
                'city' => $city, 'neighborhoods' => $neighborhoods, 'listings' => $listings, 'requests' => $requests, 'wishes' => $wishes, 'created_by' => $by->id,
            ]);
            $samples = new ArenaSamples("{$batch->id}:{$batch->created_at->getTimestamp()}");
            $brokers = $listings === 0 ? [] : array_map(fn (int $n) => $this->account($batch, CustomerRole::Broker, $n, $city), range(1, min(self::BROKERS, $listings)));
            for ($n = 1; $n <= $listings; $n++) {
                $this->listing($brokers[$n % count($brokers)], $samples->listing($city, $neighborhoods, $rentPercent), $batch, $n);
            }
            $seekers = max($wishes, (int) ceil($requests / 3));
            for ($n = 1; $n <= $seekers; $n++) {
                $seeker = $this->account($batch, CustomerRole::Seeker, $n, $city);
                if ($n <= $wishes) {
                    $wish = $samples->request($city, $neighborhoods, $rentPercent);
                    BuyerPreference::query()->create([
                        'customer_id' => $seeker->id, 'purpose' => $wish['purpose'], 'city' => $city, 'neighborhoods' => $wish['neighborhoods'],
                        'property_types' => $wish['property_types'], 'budget_min' => $wish['budget_min'], 'budget_max' => $wish['budget_max'],
                        'bedrooms' => $wish['rooms_min'], 'notes' => $wish['description'], 'is_active' => true,
                    ]);
                }
            }
            $seekerIds = Customer::query()->where('arena_batch', $batch->id)->where('role', CustomerRole::Seeker)->orderBy('id')->pluck('id')->all();
            for ($n = 1; $n <= $requests; $n++) {
                $request = SearchRequest::query()->create([
                    ...$samples->request($city, $neighborhoods, $rentPercent),
                    'customer_id' => $seekerIds[$n % count($seekerIds)],
                    'status' => RequestStatus::Published,
                    'urgent' => false,
                ]);
                $this->index->request($request);
            }

            return $batch;
        });
        Sandbox::forget();
        Audit::log('arena_generated', 'توليد بيانات في ساحة الاختبار', $batch, ['city' => $city, 'listings' => $listings, 'requests' => $requests, 'wishes' => $wishes], $by);

        return $batch;
    }

    /** @return array{listings: int, requests: int, wishes: int, accounts: int} what was removed */
    public function remove(ArenaBatch $batch, User $by): array
    {
        $accounts = $batch->accounts()->pluck('id')->all();
        $removed = DB::transaction(function () use ($batch, $accounts) {
            $listings = 0;
            foreach (Listing::withTrashed()->whereIn('customer_id', $accounts)->get() as $listing) {
                $listing->delete();
                $this->index->listing($listing);                       // out of the index first: the signal needs only the id
                $listing->forceDelete();
                $listings++;
            }
            $requests = 0;
            foreach (SearchRequest::query()->whereIn('customer_id', $accounts)->get() as $request) {
                $request->forceFill(['status' => RequestStatus::Hidden])->save();
                $this->index->request($request);
                $request->delete();
                $requests++;
            }
            $wishes = BuyerPreference::query()->whereIn('customer_id', $accounts)->delete();
            Customer::query()->whereKey($accounts)->delete();          // licences, tokens and the rest go with them (cascade)
            $batch->delete();

            return ['listings' => $listings, 'requests' => $requests, 'wishes' => $wishes, 'accounts' => count($accounts)];
        });
        Sandbox::forget();
        Audit::log('arena_removed', 'حذف بيانات من ساحة الاختبار', null, ['batch' => $batch->id, ...$removed], $by);

        return $removed;
    }

    private function account(ArenaBatch $batch, CustomerRole $role, int $n, string $city): Customer
    {
        $customer = new Customer;
        $customer->forceFill([
            'role' => $role,
            'name' => ($role === CustomerRole::Broker ? 'وسيط' : 'باحث')." ساحة الاختبار {$n}",
            'username' => "arena-{$batch->id}-{$role->value}{$n}",
            'phone' => '+999'.str_pad((string) $batch->id, 5, '0', STR_PAD_LEFT).($role === CustomerRole::Broker ? '1' : '2').str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'phone_verified_at' => now(),
            'city' => $city,
            'password' => Str::random(64),                                 // hashed by the model; nobody knows it
            'status' => AccountStatus::Active,
            'fal_status' => $role === CustomerRole::Broker ? FalStatus::Valid : FalStatus::None,
            'is_tester' => true,
            'arena_batch' => $batch->id,
        ])->save();

        return $customer;
    }

    /** @param array<string, mixed> $columns */
    private function listing(Customer $broker, array $columns, ArenaBatch $batch, int $n): void
    {
        $listing = Listing::query()->create([
            ...$columns,
            'customer_id' => $broker->id,
            'status' => ListingStatus::Published,
            'desc_clean' => $columns['desc_raw'],
            'text_review' => TextReview::Auto,
            'published_at' => now(),
            'last_activity_at' => now(),
        ]);
        AdLicense::query()->create([
            'listing_id' => $listing->id,
            'customer_id' => $broker->id,
            'licence_number' => "ARENA-{$batch->id}-{$n}",
            'source' => LicenceSource::Entered,
            'status' => LicenceStatus::Valid,
            'verified_at' => now(),
            'expires_at' => now(AdLicense::TIMEZONE)->addDays(30)->toDateString(),
        ]);
        $this->index->listing($listing);
    }
}
