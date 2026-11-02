<?php

namespace App\Models;

use App\Enums\MarketingContractStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A developer's marketing contract with Zon: activates his account; a commission is due per unit sold. */
class MarketingContract extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'status' => MarketingContractStatus::class,
            'signed_at' => 'date',
            'ends_at' => 'date',
            'commission_percent' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function developer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'developer_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
