<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An override of a business value (App\Support\Settings\Setting); read through App\Support\Settings\Settings only. */
class SettingValue extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'settings';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'json', 'updated_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
