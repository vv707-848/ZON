<?php

namespace App\Models;

use App\Enums\FloorLevel;
use App\Enums\LandUse;
use App\Enums\ListingStatus;
use App\Enums\Occupancy;
use App\Enums\PropertyType;
use App\Enums\Purpose;
use App\Enums\TextReview;
use App\Models\Concerns\HasDescriptionReview;
use Database\Factories\ListingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single property offered for sale or rent. Status changes go through App\Actions\Listings\ChangeListingStatus
 * only (state machine + history + index signal in one transaction).
 */
class Listing extends Model
{
    /** @use HasFactory<ListingFactory> */
    use HasDescriptionReview, HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'auto_hidden_at' => 'datetime',
            'status' => ListingStatus::class,
            'purpose' => Purpose::class,
            'property_type' => PropertyType::class,
            'floor_level' => FloorLevel::class,
            'occupancy' => Occupancy::class,
            'land_use' => LandUse::class,
            'text_review' => TextReview::class,
            'price' => 'decimal:2',
            'area_land' => 'decimal:2',
            'area_built' => 'decimal:2',
            'annual_income' => 'decimal:2',
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'negotiable' => 'boolean',
            'has_deed' => 'boolean',
            'bank_accepted' => 'boolean',
            'features' => 'array',
            'phones' => 'array',
            'text_reviewed_at' => 'datetime',
            'featured_until' => 'datetime',
            'urgent_until' => 'datetime',
            'paused_until' => 'datetime',
            'published_at' => 'datetime',
            'sold_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /** @param Builder<Listing> $query the status only — what the advertiser calls «published» */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', ListingStatus::Published);
    }

    /**
     * What the public sees: published, with an advertising licence that has not passed its last day. A licence
     * takes its listing out the minute it ends; App\Jobs\Scheduled\ExpireLicences records it later.
     *
     * @param  Builder<Listing>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', ListingStatus::Published)->whereHas('licence', fn (Builder $l) => AdLicense::whereCurrent($l));
    }

    public function isLive(): bool
    {
        return $this->status === ListingStatus::Published && $this->hasCurrentLicence();
    }

    /** Asked to the database, never to a relation loaded before a renewal. */
    public function hasCurrentLicence(): bool
    {
        return $this->licence()->current()->exists();
    }

    public function isFeatured(): bool
    {
        return $this->featured_until?->isFuture() ?? false;
    }

    public function isUrgent(): bool
    {
        return $this->urgent_until?->isFuture() ?? false;
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return MorphMany<Media, $this> */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')->orderByDesc('is_cover')->orderBy('sort');
    }

    /** @return HasOne<AdLicense, $this> */
    public function licence(): HasOne
    {
        return $this->hasOne(AdLicense::class)->latestOfMany();
    }

    /** @return HasMany<ListingHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(ListingHistory::class)->orderByDesc('changed_at');
    }

    /** @return HasOne<OwnerCase, $this> the owner's path that produced this listing */
    public function ownerCase(): HasOne
    {
        return $this->hasOne(OwnerCase::class);
    }
}
