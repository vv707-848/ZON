<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/** The bell: the account's notices (the app's AppNotification) and marking them read. */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $me = $request->user('api');
        $page = $me->notifications()->paginate(30);

        return Api::ok($page->getCollection()->map(fn (DatabaseNotification $n) => [
            'id' => $n->id,
            'kind' => $n->data['kind'] ?? null,
            'title' => $n->data['title'] ?? '',
            'body' => $n->data['body'] ?? '',
            'target' => $n->data['target'] ?? '/notifications',
            'key' => $n->data['key'] ?? null,
            'read' => $n->read_at !== null,
            'at' => $n->created_at?->toIso8601String(),
        ]), ['unread' => $me->unreadNotifications()->count(), 'page' => ['current' => $page->currentPage(), 'last' => $page->lastPage()]]);
    }

    /** {ids: [...]} or nothing for all. */
    public function read(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['nullable', 'array', 'max:200'], 'ids.*' => ['string']]);
        $request->user('api')->unreadNotifications()->when($data['ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))->update(['read_at' => now()]);

        return Api::ok(['unread' => $request->user('api')->unreadNotifications()->count()]);
    }
}
