<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Support\SupportDesk;
use App\Enums\ErrorCode;
use App\Enums\TicketType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\SupportTicketResource;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\SupportTicket;
use App\Support\Api;
use App\Support\Blocks;
use App\Support\Phone;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * «الدعم»: a complaint, a suggestion or a question (signed in or not) gets a reference number and the announced
 * response time; a signed-in user follows his threads, reads «فريق زون»'s messages and answers them.
 */
class SupportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = SupportTicket::query()->where('customer_id', $request->user('api')->id)->with(['lastMessage', 'about'])
            ->orderByDesc('last_message_at')->orderByDesc('id')->paginate(30);

        return Api::page($page, SupportTicketResource::class);
    }

    public function store(Request $request, SupportDesk $desk): JsonResponse
    {
        $me = $request->user('api');
        $me = $me instanceof Customer ? $me : null;
        $request->merge(['phone' => is_string($request->input('phone')) ? (Phone::normalize($request->input('phone')) ?? $request->input('phone')) : null]);
        $data = $request->validate([
            'type' => ['nullable', Rule::in(TicketType::forCustomers())],
            'subject' => ['nullable', 'string', 'max:160'],
            'listing_id' => ['nullable', 'integer'],
            'name' => [$me ? 'nullable' : 'required', 'string', 'max:120'],
            'phone' => [$me ? 'nullable' : 'required', 'regex:/^\+9665\d{8}$/'],
            'body' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $about = null;
        if (isset($data['listing_id'])) {
            $about = Listing::query()->find($data['listing_id']);
            if ($about === null || ($about->customer_id !== $me?->id && (! $about->isLive() || Blocks::hides($me, $about->customer)))) {
                throw ApiException::of(ErrorCode::NotFound);
            }
        }

        $ticket = $desk->open($me, TicketType::from($data['type'] ?? TicketType::Question->value), $data['body'], $data['subject'] ?? null,
            $about, $data['name'] ?? null, $data['phone'] ?? null);

        return Api::created(['reference' => $ticket->reference, 'status' => $ticket->status->value,
            'response_hours' => Settings::int(Setting::SupportResponseHours)]);
    }

    public function show(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->own($request, $ticket);
        $ticket->markSeenBy('customer');

        return Api::ok(SupportTicketResource::detail($ticket));
    }

    public function reply(Request $request, SupportTicket $ticket, SupportDesk $desk): JsonResponse
    {
        $this->own($request, $ticket);
        $desk->replyAsCustomer($ticket, (string) $request->validate(['body' => ['required', 'string', 'min:1', 'max:2000']])['body']);

        return Api::ok(SupportTicketResource::detail($ticket->unsetRelation('messages')));
    }

    private function own(Request $request, SupportTicket $ticket): void
    {
        if ($ticket->customer_id === null || $ticket->customer_id !== $request->user('api')->id) {
            throw ApiException::of(ErrorCode::NotFound);
        }
    }
}
