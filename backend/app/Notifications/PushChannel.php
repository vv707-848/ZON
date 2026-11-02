<?php

namespace App\Notifications;

use App\Integrations\IntegrationException;
use App\Integrations\Push\PushGateway;
use App\Models\Customer;
use App\Models\CustomerDevice;
use Illuminate\Support\Facades\Log;

/** Sends an AppNotice to the account's phones and forgets the tokens the provider says are gone. */
class PushChannel
{
    public function __construct(private readonly PushGateway $push) {}

    public function send(Customer $notifiable, AppNotice $notice): void
    {
        $tokens = $notifiable->routeNotificationForPush();
        if ($tokens === []) {
            return;
        }
        try {
            $dead = $this->push->send($tokens, $notice->title, $notice->body, ['kind' => $notice->kind->value, 'target' => $notice->target]);
        } catch (IntegrationException $e) {
            Log::warning('push.failed', ['customer' => $notifiable->id, 'error' => $e->getMessage()]);   // the bell still has it

            return;
        }
        if ($dead !== []) {
            CustomerDevice::query()->whereIn('push_token', $dead)->delete();
        }
    }
}
