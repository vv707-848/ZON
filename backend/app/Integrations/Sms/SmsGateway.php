<?php

namespace App\Integrations\Sms;

/** Text messages (verification codes). */
interface SmsGateway
{
    /** @param string $phone E.164 (+9665XXXXXXXX) */
    public function send(string $phone, string $text): void;
}
