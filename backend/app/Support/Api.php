<?php

namespace App\Support;

use App\Enums\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The response envelope of /api/v1 (backend doc §14):
 *   success → {success: true, data, meta?}   (meta left out when empty)
 *   failure → {success: false, error: {code, message, details, trace_id}}
 */
final class Api
{
    /** @param array<string, mixed> $meta */
    public static function ok(mixed $data = null, array $meta = [], int $status = 200): JsonResponse
    {
        if ($data instanceof JsonResource) {
            $data = $data->resolve(request());
        }
        $body = ['success' => true, 'data' => $data];
        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        return new JsonResponse($body, $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $meta */
    public static function created(mixed $data = null, array $meta = []): JsonResponse
    {
        return self::ok($data, $meta, 201);
    }

    /**
     * One page of a list: data = the page's items through the resource, meta.page = where the app is.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  LengthAwarePaginator<int, TModel>  $page
     * @param  class-string<JsonResource>  $resource
     * @param  array<string, mixed>  $meta
     */
    public static function page(LengthAwarePaginator $page, string $resource, array $meta = []): JsonResponse
    {
        return self::ok($resource::collection($page->getCollection())->resolve(request()), $meta + ['page' => [
            'current' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last' => $page->lastPage(),
        ]]);
    }

    /** @param array<string, mixed> $details */
    public static function error(ErrorCode $code, array $details = [], ?string $message = null, ?int $status = null): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'error' => [
                'code' => $code->value,
                'message' => $message ?? $code->message(),
                'details' => (object) $details,
                'trace_id' => Trace::id(),
            ],
        ], $status ?? $code->status(), [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
