<?php

namespace App\Models;

use App\Enums\Delivery;
use App\Enums\LandUse;
use App\Enums\ListingStatus;
use App\Enums\PropertyType;
use App\Enums\TextReview;
use App\Models\Concerns\HasDescriptionReview;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A developer's project; its unit models (project_units) are what the search finds. */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasDescriptionReview, HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'auto_hidden_at' => 'datetime',
            'status' => ListingStatus::class,
            'property_type' => PropertyType::class,
            'delivery' => Delivery::class,
            'land_use' => LandUse::class,
            'text_review' => TextReview::class,
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'phones' => 'array',
            'text_reviewed_at' => 'datetime',
            'featured_until' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /** @param Builder<Project> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', ListingStatus::Published);
    }

    /** @return BelongsTo<Customer, $this> */
    public function developer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'developer_id');
    }

    /** @return HasMany<ProjectUnit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(ProjectUnit::class)->orderBy('price');
    }

    /** @return MorphMany<Media, $this> */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')->orderByDesc('is_cover')->orderBy('sort');
    }

    /** @return HasMany<MarketingContract, $this> */
    public function contracts(): HasMany
    {
        return $this->hasMany(MarketingContract::class);
    }
}
