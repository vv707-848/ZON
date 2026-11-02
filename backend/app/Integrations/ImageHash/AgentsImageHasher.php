<?php

namespace App\Integrations\ImageHash;

use App\Support\Trace;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** The agents service's pHash (POST /media/phash, raw image body). Not an AI call: no quota, no masking. */
class AgentsImageHasher implements ImageHasher
{
    public function name(): string
    {
        return 'agents';
    }

    public function hash(string $binary): ?string
    {
        try {
            $response = Http::withHeaders(['X-Internal-Key' => (string) config('zon.agents.key'), Trace::HEADER => Trace::id()])
                ->withBody($binary, 'application/octet-stream')->timeout(10)
                ->post(rtrim((string) config('zon.agents.url'), '/').'/media/phash');
        } catch (ConnectionException) {
            return null;    // a missing hash only weakens duplicate detection; the upload goes on
        }
        $hash = $response->json('phash');

        return $response->successful() && is_string($hash) && preg_match('/^[0-9a-f]{16}$/', $hash) === 1 ? $hash : null;
    }
}
