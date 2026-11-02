<?php

namespace App\Integrations\Sms;

/** Text messages (verification codes). */
interface SmsGateway
{
    public function name(): string;

    /** @param string $phone E.164 (+9665XXXXXXXX) */
    public function send(string $phone, string $text): void;
}
