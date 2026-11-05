<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MediaResource;
use App\Integrations\ImageHash\ImageHasher;
use App\Models\Media;
use App\Support\Api;
use App\Support\Ownership;
use App\Support\Photos\PhotoRejected;
use App\Support\Photos\Photos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Photos are uploaded first (one per call), then attached with media_ids on the listing, project or unit.
 * Each is kept cleaned (App\Support\Photos\Photos): upright, without the phone's metadata, in three sizes.
 */
class MediaController extends Controller
{
    public function store(Request $request, ImageHasher $hasher): JsonResponse
    {
        $request->validate(['photo' => ['required', 'file', 'image', 'mimes:'.implode(',', Photos::extensions()), 'max:'.(int) config('zon.media.max_kb')]]);
        try {
            $photo = Photos::clean((string) $request->file('photo')->getRealPath());
        } catch (PhotoRejected $e) {
            throw ValidationException::withMessages(['photo' => $e->getMessage()]);
        }
        $customer = $request->user('api');
        $disk = (string) config('zon.media_disk');
        $path = "media/{$customer->id}/".Str::ulid().'.jpg';

        foreach ($photo->sizes as $size => $bytes) {
            Storage::disk($disk)->put(Media::sizePath($path, $size), $bytes);
        }
        $media = Media::query()->create([
            'customer_id' => $customer->id,
            'disk' => $disk,
            'path' => $path,
            'mime' => 'image/jpeg',
            'size' => strlen($photo->main()),
            'width' => $photo->width,
            'height' => $photo->height,
            'phash' => $hasher->hash($photo->main()),
            'sizes' => array_values(array_diff(array_keys($photo->sizes), ['main'])),
        ]);

        return Api::created(new MediaResource($media));
    }

    public function destroy(Request $request, Media $media): JsonResponse
    {
        Ownership::assert($media, $request->user('api'));
        $media->deleteWithFiles();          // a published copy of a stock item keeps the shared files

        return Api::ok();
    }
}
