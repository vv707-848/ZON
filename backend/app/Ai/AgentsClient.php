<?php

namespace App\Ai;

use App\Enums\AiAgent;
use App\Support\Trace;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * HTTP to the agents service (127.0.0.1:8100). Used by AiGateway ONLY (tests/Unit/ArchitectureTest): calling it
 * from anywhere else would skip masking, quotas, the cut-off, the fallback and the ai_requests record.
 * 20 s then give up (no retry after a timeout); a connection refused at once is retried a single time.
 */
final class AgentsClient
{
    /** @param array<string, mixed> $input */
    public function call(AiAgent $agent, array $input): AgentReply
    {
        $url = rtrim((string) config('zon.agents.url'), '/').'/agents/'.$agent->value;
        $started = microtime(true);

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = Http::withHeaders(['X-Internal-Key' => (string) config('zon.agents.key'), Trace::HEADER => Trace::id()])
                    ->acceptJson()
                    ->connectTimeout((int) config('zon.agents.connect_timeout'))
                    ->timeout((int) config('zon.agents.timeout'))
                    ->post($url, $input);
                break;
            } catch (ConnectionException $e) {
                $fast = microtime(true) - $started < (int) config('zon.agents.connect_timeout') + 0.5;
                if ($attempt === 1 && $fast) {
                    continue;
                }
                throw new AiUnavailable($fast ? AiUnavailable::DOWN : AiUnavailable::TIMEOUT, $e->getMessage());
            }
        }

        if ($response->status() === 422 || $response->status() === 404 || $response->status() === 401) {
            throw new AiUnavailable(AiUnavailable::REJECTED, "HTTP {$response->status()}");
        }
        if (! $response->successful() || ! is_array($response->json('output'))) {
            throw new AiUnavailable(AiUnavailable::DOWN, "HTTP {$response->status()}");
        }

        return new AgentReply(
            $response->json('output'),
            (bool) $response->json('degraded', false),
            (int) $response->json('usage.tokens_in', 0),
            (int) $response->json('usage.tokens_out', 0),
            (int) $response->json('latency_ms', (int) ((microtime(true) - $started) * 1000)),
        );
    }
}
