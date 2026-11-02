<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** «مطابق / غير مطابق» under a result; the reason is required for «غير مطابق». */
class SearchFeedback extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'search_feedback';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_match' => 'boolean', 'query_snapshot' => 'array'];
    }
}
