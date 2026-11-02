<?php

namespace App\Ai\Quota;

use Illuminate\Redis\Connections\PhpRedisConnection as Connection;

/** One reserved AI call on every layer; release() gives it back (failure, fallback, cached answer). */
final class Reservation
{
    private bool $released = false;

    /** @param list<string> $keys */
    public function __construct(private readonly Connection $redis, private readonly array $keys, public int $remaining) {}

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        foreach ($this->keys as $key) {
            $this->redis->decr($key);
        }
        $this->released = true;
        $this->remaining++;
    }
}
