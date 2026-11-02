<?php

namespace App\Models;

use App\Enums\LicenceSource;
use App\Enums\LicenceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A REGA advertising licence: issued through the owner's path, or entered by a broker / per unit and verified.
 * A listing or a unit keeps every licence it had; the latest is its licence (latestOfMany).
 *
 * The expiry is a date in Saudi time and the licence is good through that whole day: it stops counting at
 * midnight in Riyadh, the moment the listing leaves the public (Listing::live), not when a job runs.
 */
class AdLicense extends Model
{
    public const TIMEZONE = 'Asia/Riyadh';

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
            'reminded_days' => 'integer',
        ];
    }

    /** Today in Riyadh, as the Y-m-d that `expires_at` is compared with. */
    public static function today(): string
    {
        return CarbonImmutable::now(self::TIMEZONE)->toDateString();
    }

    public function isValid(): bool
    {
        return $this->status === LicenceStatus::Valid && ($this->expires_at === null || $this->expires_at->toDateString() >= self::today());
    }

    /** Whole days left after today: 0 on its last day. Null without an expiry. */
    public function daysLeft(): ?int
    {
        return $this->expires_at === null ? null
            : (int) CarbonImmutable::parse(self::today())->diffInDays(CarbonImmutable::parse($this->expires_at->toDateString()), false);
    }

    /** @param Builder<AdLicense> $query valid and not past its last day */
    public function scopeCurrent(Builder $query): void
    {
        self::whereCurrent($query);
    }

    /**
     * The same rule for a `whereHas('licence', …)` closure.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function whereCurrent(Builder $query): Builder
    {
        return $query->where('status', LicenceStatus::Valid)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', self::today()));
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
