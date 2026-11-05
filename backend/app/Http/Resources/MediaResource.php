<?php

namespace App\Http\Resources;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A photo: `url` is the main size (photo viewer, 2048 px), `large_url` 1280 px, `card_url` 640 px for lists.
 *
 * @mixin Media
 */
class MediaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url(),
            'large_url' => $this->url('large'),
            'card_url' => $this->url('card'),
            'is_cover' => $this->is_cover,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
