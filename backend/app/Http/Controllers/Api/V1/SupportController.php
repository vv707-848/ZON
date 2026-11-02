<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\SupportTicket;
use App\Support\Api;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** «تذكرة دعم» from the settings (signed in or not): a reference number now, the staff reply later. */
class SupportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return Api::ok(SupportTicket::query()->where('customer_id', $request->user('api')->id)->latest()->limit(50)->get()
            ->map(fn (SupportTicket $t) => ['reference' => $t->reference, 'status' => $t->status->value, 'body' => $t->body, 'reply' => $t->reply,
                'created_at' => $t->created_at?->toIso8601String(), 'replied_at' => $t->replied_at?->toIso8601String()]));
    }

    public function store(Request $request): JsonResponse
    {
        $me = $request->user('api');
        $me = $me instanceof Customer ? $me : null;
        $request->merge(['phone' => is_string($request->input('phone')) ? (Phone::normalize($request->input('phone')) ?? $request->input('phone')) : null]);
        $data = $request->validate([
            'name' => [$me ? 'nullable' : 'required', 'string', 'max:120'],
            'phone' => [$me ? 'nullable' : 'required', 'regex:/^\+9665\d{8}$/'],
            'body' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $ticket = SupportTicket::query()->create([
            'reference' => 'ZT-'.now()->format('ymd').'-'.Str::upper(Str::random(5)),
            'customer_id' => $me?->id,
            'name' => $me->name ?? $data['name'],
            'phone' => $me->phone ?? $data['phone'],
            'body' => $data['body'],
            'status' => TicketStatus::Open,
        ]);

        return Api::created(['reference' => $ticket->reference]);
    }
}
