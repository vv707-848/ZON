<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One day × neighbourhood × type snapshot; market reports read these, never the live tables. */
class DailyAreaStat extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['stat_date' => 'date'];
    }
}
