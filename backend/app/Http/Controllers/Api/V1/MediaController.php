<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MediaResource;
use App\Integrations\ImageHash\ImageHasher;
use App\Models\Media;
use App\Support\Api;
use App\Support\Ownership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Photos are uploaded first (one per call), then attached with media_ids on the listing, project or unit. */
class MediaController extends Controller
{
    public function store(Request $request, ImageHasher $hasher): JsonResponse
    {
        $request->validate(['photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.(int) config('zon.media.max_kb')]]);
        $file = $request->file('photo');
        $customer = $request->user('api');
        $binary = (string) file_get_contents($file->getRealPath());
        $size = @getimagesizefromstring($binary) ?: [null, null];
        $disk = (string) config('zon.media_disk');
        $path = "media/{$customer->id}/".Str::ulid().'.'.$file->guessExtension();

        Storage::disk($disk)->put($path, $binary);
        $media = Media::query()->create([
            'customer_id' => $customer->id,
            'disk' => $disk,
            'path' => $path,
            'mime' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'width' => $size[0],
            'height' => $size[1],
            'phash' => $hasher->hash($binary),
        ]);

        return Api::created(new MediaResource($media));
    }

    public function destroy(Request $request, Media $media): JsonResponse
    {
        Ownership::assert($media, $request->user('api'));
        Storage::disk($media->disk)->delete($media->path);
        $media->delete();

        return Api::ok();
    }
}
