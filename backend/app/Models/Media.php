<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A photo or document on MinIO. Uploaded first (mediable empty), then attached; orphans are pruned nightly.
 * A photo is stored cleaned (App\Support\Photos\Photos) at `path`, with its other sizes beside it
 * (`…-large.jpg`, `…-card.jpg`, listed in `sizes`). Copies (a stock item published as a listing) share the files.
 */
class Media extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_cover' => 'boolean', 'sizes' => 'array'];
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

    /** Where a size of a photo is stored, beside the main file: media/7/01J….jpg → media/7/01J…-card.jpg */
    public static function sizePath(string $path, string $size): string
    {
        return $size === 'main' ? $path : Str::beforeLast($path, '.')."-{$size}.jpg";
    }

    /** The stored path of a size, or the main file when that size was not made (older uploads, documents). */
    public function pathOf(string $size = 'main'): string
    {
        return in_array($size, (array) $this->sizes, true) ? self::sizePath($this->path, $size) : $this->path;
    }

    /** @return list<string> every file of this record */
    public function paths(): array
    {
        return [$this->path, ...array_map(fn (string $size) => self::sizePath($this->path, $size), array_values((array) $this->sizes))];
    }

    /** A signed link valid for config('zon.media.url_minutes'), served through media.zon.sa. */
    public function url(string $size = 'main'): string
    {
        $disk = Storage::disk($this->disk);
        $path = $this->pathOf($size);
        try {
            return $disk->temporaryUrl($path, now()->addMinutes((int) config('zon.media.url_minutes')));
        } catch (\RuntimeException) {
            return $disk->url($path);        // a disk without signed links (local development)
        }
    }

    /** Deletes the record, and its files when no copy points to them. */
    public function deleteWithFiles(): void
    {
        if (! self::query()->where('path', $this->path)->whereKeyNot($this->id)->exists()) {
            Storage::disk($this->disk)->delete($this->paths());
        }
        $this->delete();
    }
}
