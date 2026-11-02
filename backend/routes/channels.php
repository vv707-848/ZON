<?php

use App\Models\ConversationParticipant;
use App\Models\Customer;
use Illuminate\Support\Facades\Broadcast;

// Private channels of the app on Reverb (wss://api.zon.sa/app/{key}); bootstrap/app.php mounts the auth route.

// The account's own channel: new messages in any conversation, the bell's counter.
Broadcast::channel('customers.{id}', fn (Customer $customer, int $id) => $customer->id === $id, ['guards' => ['api']]);

// One conversation, for its participants only (not when that side hid it).
Broadcast::channel('conversations.{id}', fn (Customer $customer, int $id) => ConversationParticipant::query()
    ->where('conversation_id', $id)->where('customer_id', $customer->id)->whereNull('hidden_at')->exists(), ['guards' => ['api']]);
