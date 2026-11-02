<?php

namespace App\Ai\Assistants;

use App\Ai\AiGateway;
use App\Ai\AiResult;
use App\Enums\AiAgent;
use App\Enums\AiCallStatus;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Models\NoteSummary;

/**
 * The summary button of the desk (client · owner · tasks): the last 60 notes within 18,000 characters, masked with
 * the person's name. Unchanged notes (same fingerprint) return the stored summary — no call, no charge.
 * An unusable AI answer is 502: a summary is never made up.
 */
final class NoteSummarizer
{
    private const MAX_NOTES = 60;

    private const MAX_CHARS = 18_000;

    public function __construct(private readonly AiGateway $ai) {}

    /**
     * @param  list<array<string, string>>  $notes  newest first ({category, date, text})
     * @param  list<string>  $names  to mask
     * @return array{summary: NoteSummary, ai: array<string, mixed>}
     */
    public function summarize(Customer $customer, string $scope, int $refId, array $notes, array $names): array
    {
        $kept = [];
        $chars = 0;
        foreach (array_slice($notes, 0, self::MAX_NOTES) as $note) {
            $chars += mb_strlen($note['text']);
            if ($chars > self::MAX_CHARS) {
                break;
            }
            $kept[] = $note;
        }
        if ($kept === []) {
            throw ApiException::of(ErrorCode::ValidationFailed, ['fields' => ['notes' => ['لا توجد ملاحظات للتلخيص.']]]);
        }
        $kept = array_reverse($kept);                                    // oldest first: the story in order
        $hash = sha1($scope.'|'.json_encode($kept, JSON_UNESCAPED_UNICODE));

        $stored = NoteSummary::query()->where('scope', $scope)->where('ref_id', $refId)->first();
        if ($stored !== null && $stored->source_hash === $hash) {
            return ['summary' => $stored, 'ai' => (new AiResult(AiCallStatus::Cached, []))->meta()];
        }

        $result = $this->ai->call(AiAgent::Summarize, ['scope' => $scope, 'notes' => $kept], check: function (array $out): ?array {
            $summary = trim((string) ($out['summary'] ?? ''));
            $confidence = (string) ($out['confidence'] ?? '');

            return $summary === '' || ! in_array($confidence, ['high', 'medium', 'low'], true) ? null : [
                'summary' => $summary,
                'signals' => array_slice(array_values(array_filter((array) ($out['signals'] ?? []), 'is_string')), 0, 6),
                'recommended_action' => mb_substr(trim((string) ($out['recommended_action'] ?? '')), 0, 300),
                'confidence' => $confidence,
            ];
        }, customer: $customer, cache: false, known: $names);

        if (! $result->usable()) {
            throw ApiException::of(ErrorCode::IntegrationFailed);
        }

        $summary = NoteSummary::query()->updateOrCreate(['scope' => $scope, 'ref_id' => $refId], [
            'customer_id' => $customer->id, ...$result->output, 'source_hash' => $hash,
        ]);

        return ['summary' => $summary, 'ai' => $result->meta()];
    }
}
