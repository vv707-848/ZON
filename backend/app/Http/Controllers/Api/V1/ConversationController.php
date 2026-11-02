<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ErrorCode;
use App\Enums\RequestStatus;
use App\Events\MessageSent;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdvertiserResource;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Message;
use App\Models\Project;
use App\Models\SearchRequest;
use App\Notifications\Notices;
use App\Support\Api;
use App\Support\Blocks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * «المحادثات»: one conversation per pair and subject (a listing, a project, a request, or none), created under
 * a lock on both accounts so two first messages never open two conversations. Live on Reverb (MessageSent).
 * A block refuses messages with a general message — the blocked side never learns why.
 */
class ConversationController extends Controller
{
    /** Newest first, 30 a page; a fixed number of queries whatever the page holds. */
    public function index(Request $request): JsonResponse
    {
        $me = $request->user('api');
        $page = ConversationParticipant::query()->select('conversation_participants.*')
            ->join('conversations', 'conversations.id', '=', 'conversation_participants.conversation_id')
            ->where('conversation_participants.customer_id', $me->id)->whereNull('conversation_participants.hidden_at')
            ->with(['conversation.participants.customer', 'conversation.lastMessage', 'conversation.listing:id,title', 'conversation.project:id,title'])
            ->orderByDesc('conversations.last_message_at')->orderByDesc('conversations.id')
            ->paginate(30);
        $unread = $this->unread($me, $page->getCollection()->pluck('conversation_id')->all());
        $hidden = Blocks::hiddenFor($me);

        return Api::ok($page->getCollection()->map(function (ConversationParticipant $mine) use ($me, $hidden, $unread) {
            $conversation = $mine->conversation;
            $other = $conversation->participants->firstWhere('customer_id', '!=', $me->id)?->customer;
            $last = $conversation->lastMessage;

            return [
                'id' => $conversation->id,
                'with' => $other ? new AdvertiserResource($other) : null,
                'blocked' => $other !== null && in_array($other->id, $hidden, true),
                'subject' => $this->subject($conversation),
                'last_message' => $last === null ? null : ['body' => $last->body, 'mine' => $last->sender_id === $me->id, 'at' => $last->created_at?->toIso8601String()],
                'unread' => (int) ($unread[$conversation->id] ?? 0),
            ];
        }), ['page' => ['current' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()]]);
    }

    /**
     * Messages from the others since my last reading, per conversation, in one query.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function unread(Customer $me, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Message::query()
            ->join('conversation_participants as mine', fn ($j) => $j->on('mine.conversation_id', '=', 'messages.conversation_id')->where('mine.customer_id', $me->id))
            ->whereIn('messages.conversation_id', $ids)->where('messages.sender_id', '!=', $me->id)
            ->where(fn ($q) => $q->whereNull('mine.last_read_at')->orWhereColumn('messages.created_at', '>', 'mine.last_read_at'))
            ->groupBy('messages.conversation_id')->selectRaw('messages.conversation_id, COUNT(*) AS unread')
            ->pluck('unread', 'messages.conversation_id')->map(fn ($n) => (int) $n)->all();
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string'],
            'listing_id' => ['nullable', 'integer', 'exists:listings,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'request_id' => ['nullable', 'integer', 'exists:requests,id'],
            'body' => ['required', 'string', 'max:2000'],
        ]);
        $me = $request->user('api');
        $other = Customer::query()->where('username', $data['to'])->whereNull('anonymized_at')->first();
        if ($other === null || $other->id === $me->id) {
            throw ApiException::of(ErrorCode::NotFound);
        }
        if (Blocks::between($me, $other)) {
            throw ApiException::of(ErrorCode::UserBlocked);
        }
        $this->assertSubject($data, $me, $other);

        $conversation = DB::transaction(function () use ($me, $other, $data) {
            Customer::query()->whereKey([$me->id, $other->id])->orderBy('id')->lockForUpdate()->get();
            $subject = array_filter(['listing_id' => $data['listing_id'] ?? null, 'project_id' => $data['project_id'] ?? null, 'request_id' => $data['request_id'] ?? null]);
            $query = Conversation::query()
                ->whereHas('participants', fn ($q) => $q->where('customer_id', $me->id))
                ->whereHas('participants', fn ($q) => $q->where('customer_id', $other->id));
            foreach (['listing_id', 'project_id', 'request_id'] as $column) {
                isset($subject[$column]) ? $query->where($column, $subject[$column]) : $query->whereNull($column);
            }
            $existing = $query->first();
            if ($existing !== null) {
                return $existing;
            }
            $conversation = Conversation::query()->create($subject);
            $conversation->participants()->createMany([['customer_id' => $me->id], ['customer_id' => $other->id]]);

            return $conversation;
        });
        $message = $this->post($conversation, $me, $data['body']);

        return Api::created(['conversation_id' => $conversation->id, 'message' => $this->present($message, $me)]);
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user('api');
        $this->participant($conversation, $me);
        $before = $request->integer('before') ?: null;
        $messages = $conversation->messages()->when($before, fn ($q) => $q->where('id', '<', $before))->latest('id')->limit(50)->get();

        return Api::ok($messages->reverse()->values()->map(fn (Message $m) => $this->present($m, $me)), ['has_more' => $messages->count() === 50]);
    }

    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user('api');
        $this->participant($conversation, $me);
        $body = (string) $request->validate(['body' => ['required', 'string', 'max:2000']])['body'];

        return Api::created($this->present($this->post($conversation, $me, $body), $me));
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $this->participant($conversation, $request->user('api'))->forceFill(['last_read_at' => now()])->save();

        return Api::ok();
    }

    private function post(Conversation $conversation, Customer $me, string $body): Message
    {
        $others = $conversation->participants()->where('customer_id', '!=', $me->id)->with('customer')->get();
        foreach ($others as $other) {
            if (Blocks::between($me, $other->customer)) {
                throw ApiException::of(ErrorCode::UserBlocked);
            }
        }

        $message = DB::transaction(function () use ($conversation, $me, $body) {
            $message = $conversation->messages()->create(['sender_id' => $me->id, 'body' => trim($body)]);
            $conversation->forceFill(['last_message_at' => now()])->save();
            $conversation->participants()->where('customer_id', $me->id)->update(['last_read_at' => now()]);

            return $message;
        });

        $visible = $others->whereNull('hidden_at');
        broadcast(new MessageSent($message, $visible->pluck('customer_id')->map(fn ($id) => (int) $id)->all()));
        $visible->each(fn (ConversationParticipant $p) => $p->customer->notify(Notices::newMessage($conversation->id, $me, $message->body)));

        return $message;
    }

    /**
     * The subject of a new conversation is something public that belongs to one of the two: a draft or a stranger's
     * listing would put its title in the conversation (and in the other side's list).
     *
     * @param  array<string, mixed>  $data
     */
    private function assertSubject(array $data, Customer $me, Customer $other): void
    {
        $mineOrTheirs = fn (?int $ownerId) => $ownerId === $me->id || $ownerId === $other->id;
        $ok = match (true) {
            isset($data['listing_id']) => ($l = Listing::query()->find($data['listing_id'])) !== null && $l->status->isPublic() && $mineOrTheirs($l->customer_id),
            isset($data['project_id']) => ($p = Project::query()->find($data['project_id'])) !== null && $p->status->isPublic() && $mineOrTheirs($p->developer_id),
            isset($data['request_id']) => ($r = SearchRequest::query()->find($data['request_id'])) !== null && $r->status === RequestStatus::Published && $mineOrTheirs($r->customer_id),
            default => true,
        };
        if (! $ok) {
            throw ApiException::of(ErrorCode::NotFound);
        }
    }

    private function participant(Conversation $conversation, Customer $me): ConversationParticipant
    {
        return $conversation->participants()->where('customer_id', $me->id)->whereNull('hidden_at')->first()
            ?? throw ApiException::of(ErrorCode::NotFound);
    }

    /** @return array<string, mixed> */
    private function present(Message $message, Customer $me): array
    {
        return ['id' => $message->id, 'body' => $message->body, 'mine' => $message->sender_id === $me->id, 'at' => $message->created_at?->toIso8601String()];
    }

    /** @return ?array{type: string, id: int, title: ?string} */
    private function subject(Conversation $conversation): ?array
    {
        return match (true) {
            $conversation->listing_id !== null => ['type' => 'listing', 'id' => $conversation->listing_id, 'title' => $conversation->listing?->title],
            $conversation->project_id !== null => ['type' => 'project', 'id' => $conversation->project_id, 'title' => $conversation->project?->title],
            $conversation->request_id !== null => ['type' => 'request', 'id' => $conversation->request_id, 'title' => null],
            default => null,
        };
    }
}
