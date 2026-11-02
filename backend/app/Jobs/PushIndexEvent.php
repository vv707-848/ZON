<?php

namespace App\Jobs;

use App\Indexing\IndexSignature;
use App\Models\IndexEvent;
use App\Support\Trace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends one index signal to the agents service (queue `index`, one worker, in order). Signed with HMAC;
 * 3 tries (10 s · 60 s · 300 s); a 4xx is final. A signal still unacknowledged stays in index_events and is
 * sent again by RetryPendingIndexEvents. Its trace id is the one of the action that caused it.
 */
class PushIndexEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $eventId)
    {
        $this->onQueue('index');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(): void
    {
        $event = IndexEvent::query()->find($this->eventId);
        if ($event === null || $event->acked_at !== null) {
            return;
        }
        if ($event->trace_id !== null) {
            Context::add(Trace::KEY, $event->trace_id);
        }

        $body = (string) json_encode(array_filter([
            'entity' => $event->entity->value,
            'entity_id' => $event->entity_id,
            'action' => $event->action->value,
            'version' => $event->version,
            'occurred_at' => $event->created_at?->toIso8601ZuluString(),
            'payload' => $event->payload,
        ], fn ($value) => $value !== null), JSON_UNESCAPED_UNICODE);

        $event->forceFill(['sent_at' => now(), 'attempts' => $event->attempts + 1])->save();
        try {
            $response = Http::withHeaders(IndexSignature::headers($body) + [Trace::HEADER => Trace::id()])
                ->connectTimeout((int) config('zon.index.connect_timeout'))->timeout((int) config('zon.index.timeout'))
                ->withBody($body, 'application/json')
                ->post(rtrim((string) config('zon.agents.url'), '/').'/index/'.$event->entity->value);
        } catch (ConnectionException $e) {
            $this->failWith($event, 'connection: '.$e->getMessage(), retry: true);

            return;
        }

        if ($response->successful()) {
            $event->forceFill(['acked_at' => now(), 'last_error' => null])->save();

            return;
        }
        $this->failWith($event, "HTTP {$response->status()}: ".mb_substr($response->body(), 0, 500), retry: $response->serverError());
    }

    private function failWith(IndexEvent $event, string $error, bool $retry): void
    {
        $event->forceFill(['last_error' => $error])->save();
        Log::warning('index.signal_failed', ['event' => $event->id, 'entity' => $event->entity->value, 'error' => $error]);
        if ($retry && $this->attempts() < $this->tries) {
            $this->release($this->backoff()[$this->attempts() - 1] ?? 300);
        }
    }
}
