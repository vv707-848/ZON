<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** A new message, live on Reverb: to the conversation and to each participant's own channel (the chats list). */
class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $queue = 'notify';

    /** @param list<int> $recipientIds */
    public function __construct(public readonly Message $message, public readonly array $recipientIds) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("conversations.{$this->message->conversation_id}"),
            ...array_map(fn (int $id) => new PrivateChannel("customers.{$id}"), $this->recipientIds),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'sender_id' => $this->message->sender_id,
            'body' => $this->message->body,
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
