<?php

namespace App\Models;

use App\Enums\Delivery;
use App\Enums\LandUse;
use App\Enums\LicenceStatus;
use App\Enums\PropertyType;
use App\Enums\TextReview;
use App\Models\Concerns\HasDescriptionReview;
use Database\Factories\ProjectUnitFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** One unit MODEL of a project («C1 — 6 available»), one fixed price; indexed on its own description. */
class ProjectUnit extends Model
{
    /** @use HasFactory<ProjectUnitFactory> */
    use HasDescriptionReview, HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'property_type' => PropertyType::class,
            'land_use' => LandUse::class,
            'text_review' => TextReview::class,
            'price' => 'decimal:2',
            'area' => 'decimal:2',
            'area_land' => 'decimal:2',
            'area_built' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasOne<AdLicense, $this> */
    public function licence(): HasOne
    {
        return $this->hasOne(AdLicense::class)->latestOfMany();
    }

    /** @return MorphMany<Media, $this> */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')->orderByDesc('is_cover')->orderBy('sort');
    }

    /**
     * May the public see it: a unit model of a READY project is advertised only with a valid REGA advertising
     * licence (an off-plan project is covered by its own licence). Checked at publication (PublishProject) and
     * here, for models added or edited after it.
     */
    public function isAdvertisable(Project $project): bool
    {
        return $project->delivery !== Delivery::Ready || ($this->licence?->isValid() ?? false);
    }

    /** The same rule in SQL. @param Builder<ProjectUnit> $query */
    public function scopeAdvertisable(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereHas('project', fn (Builder $p) => $p->where('delivery', '!=', Delivery::Ready))
            ->orWhereHas('licence', fn (Builder $l) => $l->where('status', LicenceStatus::Valid)
                ->where(fn (Builder $e) => $e->whereNull('expires_at')->orWhere('expires_at', '>', now()))));
    }

    /** Searchable: advertisable, a decided text and at least one unit left (the index is told when this changes). */
    public function isSearchable(): bool
    {
        return $this->available_count > 0 && $this->textDecided() && $this->isAdvertisable($this->project);
    }
}
