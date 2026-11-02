<?php

namespace App\Ai\Retrieval;

use App\Enums\AccountStatus;
use App\Enums\ClientStatus;
use App\Enums\CustomerRole;
use App\Models\BuyerPreference;
use App\Models\Customer;
use App\Models\Project;
use App\Support\Blocks;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who a developer's project could interest: seekers through their live wishes (city + neighbourhood + one of the
 * project's types, or no type), and brokers by the NUMBER of their matching clients only — never the clients.
 */
final class ProjectAudience
{
    /** @return Collection<int, BuyerPreference> at most 200 */
    public function searchers(Project $project): Collection
    {
        $types = $project->units()->reorder()->distinct()->pluck('property_type')->map(fn ($t) => $t instanceof \BackedEnum ? $t->value : $t)->all();

        return BuyerPreference::query()->with('customer')->where('is_active', true)->where('city', $project->city)
            ->whereJsonContains('neighborhoods', $project->neighborhood)
            ->where('updated_at', '>', now()->subDays(Settings::int(Setting::PreferenceActiveDays)))
            ->where(function (Builder $q) use ($types) {
                $q->whereJsonLength('property_types', 0);
                foreach ($types as $type) {
                    $q->orWhereJsonContains('property_types', $type);
                }
            })
            ->whereHas('customer', fn (Builder $c) => $c->where('role', CustomerRole::Seeker)->where('status', AccountStatus::Active)->whereNull('anonymized_at')
                ->whereNotIn('id', Blocks::hiddenFor($project->developer)))
            ->latest('updated_at')->limit(200)->get();
    }

    /** @return array<string, mixed> a seeker as match_searchers sees him — his wish only, no name, no phone */
    public static function toAgent(BuyerPreference $wish): array
    {
        return [
            'id' => $wish->id,
            'type' => $wish->property_types[0] ?? null,
            'budget_min' => $wish->budget_min === null ? null : (float) $wish->budget_min,
            'budget_max' => $wish->budget_max === null ? null : (float) $wish->budget_max,
            'bedrooms' => $wish->bedrooms,
            'notes' => mb_substr((string) $wish->notes, 0, 500),
        ];
    }

    /** @return list<array{broker: Customer, clients: int}> brokers of the city with matching active clients, most first */
    public function brokers(Project $project): array
    {
        $counts = DB::table('crm_clients')->join('customers', 'customers.id', '=', 'crm_clients.broker_id')
            ->where('customers.role', CustomerRole::Broker->value)->where('customers.status', AccountStatus::Active->value)
            ->whereNotIn('customers.id', Blocks::hiddenFor($project->developer))
            ->where('crm_clients.city', $project->city)->where('crm_clients.status', ClientStatus::Active->value)
            ->where(fn ($q) => $q->whereNull('crm_clients.neighborhoods')->orWhereJsonLength('crm_clients.neighborhoods', 0)->orWhereJsonContains('crm_clients.neighborhoods', $project->neighborhood))
            ->groupBy('crm_clients.broker_id')->orderByDesc(DB::raw('COUNT(*)'))->limit(50)
            ->pluck(DB::raw('COUNT(*) as clients'), 'crm_clients.broker_id');
        $brokers = Customer::query()->whereKey($counts->keys())->get()->keyBy('id');

        return $counts->map(fn ($count, $id) => ['broker' => $brokers[$id], 'clients' => (int) $count])->values()->all();
    }
}
