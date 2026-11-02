<?php

namespace App\Support;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Models\Media;
use Illuminate\Database\Eloquent\Model;

/** Attaches uploaded photos to what they illustrate: the uploader's own, in the app's order, one cover. */
final class MediaLibrary
{
    /**
     * @param  list<int>  $ids  in display order; photos left out are detached (pruned later)
     * @return ?string the cover's perceptual hash
     */
    public static function sync(Model $owner, Customer $customer, array $ids, ?int $coverId = null): ?string
    {
        $type = $owner->getMorphClass();
        $media = Media::query()->whereKey($ids)->where('customer_id', $customer->id)
            ->where(fn ($q) => $q->whereNull('mediable_id')->orWhere(fn ($q) => $q->where('mediable_type', $type)->where('mediable_id', $owner->getKey())))
            ->get()->keyBy('id');
        if ($media->count() !== count($ids)) {
            throw ApiException::of(ErrorCode::ValidationFailed, ['fields' => ['media_ids' => [__('validation.exists', ['attribute' => __('validation.attributes.media_ids')])]]]);
        }

        Media::query()->where('mediable_type', $type)->where('mediable_id', $owner->getKey())->whereNotIn('id', $ids)
            ->update(['mediable_type' => null, 'mediable_id' => null, 'is_cover' => false]);

        $coverId = in_array($coverId, $ids, true) ? $coverId : ($ids[0] ?? null);
        foreach ($ids as $sort => $id) {
            $media[$id]->forceFill(['mediable_type' => $type, 'mediable_id' => $owner->getKey(), 'sort' => $sort, 'is_cover' => $id === $coverId])->save();
        }

        return $coverId === null ? null : $media[$coverId]->phash;
    }

    /** Copies photos onto a new owner (stock item → listing), as new rows pointing to the same files. */
    public static function copy(Model $from, Model $to): void
    {
        foreach (Media::query()->where('mediable_type', $from->getMorphClass())->where('mediable_id', $from->getKey())->get() as $media) {
            $media->replicate()->fill(['mediable_type' => $to->getMorphClass(), 'mediable_id' => $to->getKey()])->save();
        }
    }
}
