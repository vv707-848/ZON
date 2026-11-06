<?php

namespace App\Integrations\Push;

/** Push notifications to the app (FCM, which also reaches iOS through APNs). */
interface PushGateway
{
    /**
     * @param  list<string>  $tokens
     * @param  array<string, scalar>  $data  sent as strings (FCM's rule)
     * @return list<string> tokens the provider says no longer exist (the caller deletes them)
     */
    public function send(array $tokens, string $title, string $body, array $data = []): array;
}
