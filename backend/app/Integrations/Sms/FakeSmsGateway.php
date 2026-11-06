<?php

namespace App\Integrations\Sms;

use App\Support\Phone;
use Illuminate\Support\Facades\Log;

/**
 * SIMULATION — sends nothing. The text goes to the log so a developer can read the code; tests read sent().
 * Never used in production unless PRODUCTION_ALLOWS_FAKE names "sms".
 */
class FakeSmsGateway implements SmsGateway
{
    /** @var list<array{phone: string, text: string}> */
    private array $sent = [];

    public function send(string $phone, string $text): void
    {
        $this->sent[] = ['phone' => $phone, 'text' => $text];
        Log::info('sms.simulated', ['phone' => Phone::masked($phone), 'text' => $text]);
    }

    /** @return list<array{phone: string, text: string}> */
    public function sent(): array
    {
        return $this->sent;
    }
}
