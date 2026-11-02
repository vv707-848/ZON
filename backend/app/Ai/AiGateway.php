<?php

namespace App\Ai;

use App\Ai\Masking\AiMasking;
use App\Ai\Quota\AiQuota;
use App\Enums\AiAgent;
use App\Enums\AiCallStatus;
use App\Models\AiRequest;
use App\Models\Customer;
use App\Models\User;
use App\Support\Trace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Every AI call of Laravel passes here, in this order:
 *   1 masking (AiMasking, on every string) · 2 fingerprint of the masked input · 3 cache (15 min) ·
 *   4 the call (AgentsClient, 20 s) · 5 the caller's check of the answer (ids from the candidates only, reasons
 *   clipped) · 6 the quota is consumed on a full answer only · 7 one ai_requests row · 8 null output → the
 *   caller's fallback. Nothing personal is logged: the fingerprint, the status, the cost, the trace id.
 */
final class AiGateway
{
    public function __construct(private readonly AgentsClient $client) {}

    /**
     * @param  array<string, mixed>  $input  built from whitelists by the caller
     * @param  ?callable(array<string, mixed>): ?array<string, mixed>  $check  returns the cleaned output, or null if unusable
     * @param  list<string>  $known  names to mask (a client's name)
     */
    public function call(AiAgent $agent, array $input, ?callable $check = null, ?Customer $customer = null, ?User $staff = null, bool $cache = true, array $known = []): AiResult
    {
        $input = AiMasking::payload($input, $known);
        $hash = hash('sha256', $agent->value.'|'.json_encode($input, JSON_UNESCAPED_UNICODE));
        $key = "ai:{$agent->value}:{$hash}";

        if ($cache && is_array($cached = Cache::get($key))) {
            $this->record($agent, AiCallStatus::Cached, $hash, $customer, $staff);

            return new AiResult(AiCallStatus::Cached, $cached);
        }

        try {
            $reply = $this->client->call($agent, $input);
        } catch (AiUnavailable $e) {
            $status = match ($e->reason) {
                AiUnavailable::TIMEOUT => AiCallStatus::Timeout,
                AiUnavailable::REJECTED => AiCallStatus::Rejected,
                default => AiCallStatus::Fallback,
            };
            Log::warning('ai.unavailable', ['agent' => $agent->value, 'reason' => $e->getMessage()]);
            $this->record($agent, $status, $hash, $customer, $staff);

            return new AiResult($status, null);
        }

        $output = $check === null ? $reply->output : $check($reply->output);
        if ($output === null) {
            Log::warning('ai.invalid_answer', ['agent' => $agent->value]);
            $this->record($agent, AiCallStatus::Rejected, $hash, $customer, $staff, $reply);

            return new AiResult(AiCallStatus::Rejected, null);
        }

        $status = $reply->degraded ? AiCallStatus::Degraded : AiCallStatus::Ok;
        if ($status === AiCallStatus::Ok) {
            AiQuota::consume();
            if ($cache) {
                Cache::put($key, $output, now()->addMinutes((int) config('zon.ai.cache_minutes')));
            }
        }
        $this->record($agent, $status, $hash, $customer, $staff, $reply);

        return new AiResult($status, $output);
    }

    /**
     * Ranked results kept only when their id is one we offered, at most `limit`, reasons clipped to 12 words.
     *
     * @param  mixed  $results  the model's list
     * @param  list<int>  $allowed
     * @return list<array{id: int, score: float, reason: string}>
     */
    public static function ranked(mixed $results, array $allowed, ?int $limit = null): array
    {
        $allowed = array_flip($allowed);
        $kept = [];
        foreach (is_array($results) ? $results : [] as $row) {
            $id = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
            if (! isset($allowed[$id]) || isset($kept[$id])) {
                continue;
            }
            $kept[$id] = ['id' => $id, 'score' => max(0.0, min(1.0, (float) ($row['score'] ?? 0))), 'reason' => self::clip((string) ($row['reason'] ?? ''))] + array_diff_key($row, array_flip(['id', 'score', 'reason']));
        }

        return array_slice(array_values($kept), 0, $limit ?? (int) config('zon.ai.results'));
    }

    public static function clip(string $reason): string
    {
        $words = preg_split('/\s+/u', trim($reason), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_slice($words, 0, (int) config('zon.ai.reason_max_words')));
    }

    private function record(AiAgent $agent, AiCallStatus $status, string $hash, ?Customer $customer, ?User $staff, ?AgentReply $reply = null): void
    {
        AiRequest::query()->create([
            'customer_id' => $customer?->id,
            'staff_id' => $staff?->id,
            'agent' => $agent,
            'status' => $status,
            'tokens_in' => $reply->tokensIn ?? 0,
            'tokens_out' => $reply->tokensOut ?? 0,
            'latency_ms' => $reply->latencyMs ?? 0,
            'request_hash' => $hash,
            'trace_id' => Trace::id(),
            'created_at' => now(),
        ]);
    }
}
