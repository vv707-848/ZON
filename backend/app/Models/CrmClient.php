<?php

namespace App\Models;

use App\Enums\ClientStatus;
use App\Enums\Purpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A broker's or developer's client (isolation: every query carries broker_id = the signed-in account). */
class CrmClient extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'status' => ClientStatus::class,
            'purpose' => Purpose::class,
            'neighborhoods' => 'array',
            'property_types' => 'array',
            'budget_min' => 'decimal:2',
            'budget_max' => 'decimal:2',
            'area_min' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function broker(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'broker_id');
    }

    /** @return HasMany<ClientNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(ClientNote::class, 'client_id')->latest('id');
    }

    /** @return BelongsTo<SearchRequest, $this> the area request it was added from */
    public function sourceRequest(): BelongsTo
    {
        return $this->belongsTo(SearchRequest::class, 'source_request_id');
    }
}
