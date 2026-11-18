<?php

namespace App\Models;

use App\Enums\PropertyType;
use App\Enums\Purpose;
use App\Support\Site\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A market brief sold to a developer («تقارير المطوّرين»): the market reports of one city as they ran on the day it was
 * made (App\SuperAgent\BriefBuilder), opened on the website by its link until it expires.
 *
 * @property list<array{report: string, title: string, why: string, columns: array<string, string>, rows: list<array<string, mixed>>}> $sections
 * @property list<string> $highlights
 */
class MarketBrief extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['purpose' => Purpose::class, 'property_type' => PropertyType::class, 'sections' => 'array', 'highlights' => 'array',
            'paid_at' => 'datetime', 'expires_at' => 'datetime', 'price_halalas' => 'integer', 'days' => 'integer', 'views_count' => 'integer'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return $this->expires_at->isFuture();
    }

    public function url(): string
    {
        return Site::url('market-brief/'.$this->token);
    }

    /** «الرياض · النرجس · شقة · للبيع» */
    public function scope(): string
    {
        return implode(' · ', array_filter([$this->city, $this->neighborhood, $this->property_type?->label(), $this->purpose->label()]));
    }
}
