<?php

namespace App\Ai;

use App\Enums\AiCallStatus;

/** What AiGateway gives back: an output (null when the caller must use its fallback) and how it was obtained. */
final readonly class AiResult
{
    /** @param ?array<string, mixed> $output */
    public function __construct(public AiCallStatus $status, public ?array $output) {}

    public function usable(): bool
    {
        return $this->output !== null;
    }

    /**
     * `meta.ai` of the API answer: {status} — how the answer was obtained.
     *
     * @return array{status: string}
     */
    public function meta(): array
    {
        return ['status' => $this->status->value];
    }

    /**
     * `meta.ai` when no call was needed (no candidates, out of scope…).
     *
     * @return array{status: string}
     */
    public static function skipped(): array
    {
        return ['status' => 'skipped'];
    }
}
