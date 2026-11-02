<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/** A photo or document on MinIO. Uploaded first (mediable empty), then attached; orphans are pruned nightly. */
class Media extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_cover' => 'boolean'];
    }

    /** @return MorphTo<Model, $this> */
    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** A signed link valid for config('zon.media.url_minutes'), served through media.zon.sa. */
    public function url(): string
    {
        $disk = Storage::disk($this->disk);
        try {
            return $disk->temporaryUrl($this->path, now()->addMinutes((int) config('zon.media.url_minutes')));
        } catch (\RuntimeException) {
            return $disk->url($this->path);        // a disk without signed links (local development)
        }
    }
}
