<?php

namespace App\Http\Resources;

use App\Enums\TicketStatus;
use App\Models\Listing;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * A support thread as the user sees it: staff are «فريق زون» (author `zon`), never a name. The list carries the
 * last message; the thread (detail) carries every message.
 *
 * @mixin SupportTicket
 */
class SupportTicketResource extends JsonResource
{
    private bool $detailed = false;

    public static function detail(SupportTicket $ticket): self
    {
        $resource = new self($ticket->loadMissing(['messages', 'about']));
        $resource->detailed = true;

        return $resource;
    }

    /** @return array<int|string, mixed> mergeWhen() adds the messages under an integer key until the response */
    public function toArray(Request $request): array
    {
        $author = fn (SupportMessage $m) => $m->author === 'staff' ? 'zon' : 'me';

        return [
            'reference' => $this->reference,
            'type' => $this->type->value,
            'subject' => $this->subject,
            'status' => $this->status->value,
            'opened_by' => $this->opened_by === 'staff' ? 'zon' : 'me',
            'about' => $this->about instanceof Listing ? ['type' => 'listing', 'id' => $this->about->id, 'title' => $this->about->title] : null,
            'unread' => $this->unreadBy('customer'),
            'last_message' => $this->lastMessage === null ? null : [
                'author' => $author($this->lastMessage),
                'excerpt' => Str::limit($this->lastMessage->body, 100),
                'at' => $this->lastMessage->created_at?->toIso8601String(),
            ],
            'resolution' => $this->status === TicketStatus::Closed ? $this->resolution : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            $this->mergeWhen($this->detailed, fn () => [
                'messages' => $this->messages->map(fn (SupportMessage $m) => [
                    'author' => $author($m), 'body' => $m->body, 'at' => $m->created_at?->toIso8601String(),
                ])->all(),
            ]),
        ];
    }
}
