<?php

namespace App\Models;

use App\Enums\Purpose;
use App\Enums\RequestStatus;
use Database\Factories\SearchRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A search request («طلب») of the `requests` table — normal (free) or urgent (paid or from a quota). */
class SearchRequest extends Model
{
    /** @use HasFactory<SearchRequestFactory> */
    use HasFactory;

    protected $table = 'requests';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'published'];

    protected function casts(): array
    {
        return [
            'auto_hidden_at' => 'datetime',
            'status' => RequestStatus::class,
            'purpose' => Purpose::class,
            'neighborhoods' => 'array',
            'property_types' => 'array',
            'budget_min' => 'decimal:2',
            'budget_max' => 'decimal:2',
            'area_min' => 'decimal:2',
            'area_max' => 'decimal:2',
            'urgent' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
