<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One generation of «ساحة الاختبار» (App\Arena\TestArena): what it made, where, and who asked for it.
 *
 * @property int $id
 * @property string $city
 * @property list<string> $neighborhoods
 * @property int $listings
 * @property int $requests
 * @property int $wishes
 * @property ?int $created_by
 * @property Carbon $created_at
 */
class ArenaBatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['neighborhoods' => 'array', 'listings' => 'integer', 'requests' => 'integer', 'wishes' => 'integer'];
    }

    /** @return HasMany<Customer, $this> the batch's own sandbox accounts */
    public function accounts(): HasMany
    {
        return $this->hasMany(Customer::class, 'arena_batch');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
