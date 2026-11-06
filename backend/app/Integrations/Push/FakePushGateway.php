<?php

namespace App\Integrations\Push;

use Illuminate\Support\Facades\Log;

/** SIMULATION — nothing reaches a phone; the in-app bell (database notifications) still works. */
class FakePushGateway implements PushGateway
{
    /** @var list<array{tokens: list<string>, title: string, body: string, data: array<string, scalar>}> */
    private array $sent = [];

    public function send(array $tokens, string $title, string $body, array $data = []): array
    {
        $this->sent[] = compact('tokens', 'title', 'body', 'data');
        Log::debug('push.simulated', ['tokens' => count($tokens), 'title' => $title]);

        return [];
    }

    /** @return list<array{tokens: list<string>, title: string, body: string, data: array<string, scalar>}> */
    public function sent(): array
    {
        return $this->sent;
    }
}
