<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A daily counter of the dashboard (signups.broker, listings.published, revenue.subscription, ai.calls…). */
class DailyMetric extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['stat_date' => 'date', 'value' => 'decimal:2'];
    }
}
