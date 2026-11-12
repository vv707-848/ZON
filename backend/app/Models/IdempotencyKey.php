<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * An operation the app marked with an Idempotency-Key, and the answer it got (App\Http\Middleware\Idempotent).
 * Kept a day: the app retries within seconds.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $key
 * @property string $fingerprint
 * @property int|null $status_code
 * @property string|null $response
 */
class IdempotencyKey extends Model
{
    use MassPrunable;

    protected $guarded = ['id'];

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDay());
    }
}
