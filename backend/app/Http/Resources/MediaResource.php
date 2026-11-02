<?php

namespace App\Http\Resources;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Media */
class MediaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'url' => $this->url(), 'is_cover' => $this->is_cover, 'width' => $this->width, 'height' => $this->height];
    }
}
