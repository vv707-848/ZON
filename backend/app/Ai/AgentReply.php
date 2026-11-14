<?php

namespace App\Ai;

/** A successful answer of the agents service (contract §2). */
final readonly class AgentReply
{
    /** @param array<string, mixed> $output */
    public function __construct(
        public array $output,
        public bool $degraded,
        public int $tokensIn,
        public int $tokensOut,
        public int $latencyMs,
        public bool $simulated = false,   // answered by the simulation (agents/zon_agents/sim), not by a model
    ) {}
}
