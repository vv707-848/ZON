<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Favorite;
use Illuminate\Http\Request;

/** «إعجاب» shown on what a viewer is sent: which of [ids] of [type] he liked (the resources read is_favorite from it). */
final class Favorites
{
    /** @param list<int> $ids */
    public static function mark(Request $request, string $type, array $ids): void
    {
        $viewer = $request->user('api');
        if (! $viewer instanceof Customer || $ids === []) {
            return;
        }
        $request->attributes->set("favorite_{$type}_ids", Favorite::query()->where('customer_id', $viewer->id)->where('favoritable_type', $type)
            ->whereIn('favoritable_id', $ids)->pluck('favoritable_id')->map(fn ($id) => (int) $id)->all());
    }

    public static function of(Request $request, string $type, int $id): bool
    {
        return in_array($id, (array) $request->attributes->get("favorite_{$type}_ids", []), true);
    }
}
