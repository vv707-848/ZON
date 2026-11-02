<?php

namespace App\Models;

use App\Enums\LicenceSource;
use App\Enums\LicenceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A REGA advertising licence: issued through the owner's path, or entered by a broker / per unit and verified. */
class AdLicense extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'source' => LicenceSource::class,
            'status' => LicenceStatus::class,
            'verified_at' => 'datetime',
            'issued_at' => 'datetime',
            'expires_at' => 'date',
        ];
    }

    public function isValid(): bool
    {
        return $this->status === LicenceStatus::Valid && ($this->expires_at === null || ! $this->expires_at->isPast());
    }

    /** @return BelongsTo<Listing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class)->withTrashed();
    }

    /** @return BelongsTo<ProjectUnit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProjectUnit::class, 'project_unit_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
