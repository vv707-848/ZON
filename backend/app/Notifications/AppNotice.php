<?php

namespace App\Notifications;

use App\Enums\NoteKind;
use App\Enums\NoticePref;
use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * One notice of the app: the bell (database, as the app's AppNotification {kind, title, body, target, key})
 * and a push to the account's phones. A notice tied to one of the six switches is not sent when it is off.
 * Every notice is built in App\Notifications\Notices.
 */
class AppNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly NoteKind $kind,
        public readonly string $title,
        public readonly string $body,
        public readonly string $target,
        public readonly ?NoticePref $pref = null,
        public readonly ?string $key = null,
    ) {
        $this->onQueue('notify');
    }

    /** @return list<string> */
    public function via(Customer $notifiable): array
    {
        if ($notifiable->anonymized_at !== null || ($this->pref !== null && ! $notifiable->wants($this->pref))) {
            return [];
        }

        return ['database', PushChannel::class];
    }

    /** @return array<string, ?string> */
    public function toArray(Customer $notifiable): array
    {
        return ['kind' => $this->kind->value, 'title' => $this->title, 'body' => $this->body, 'target' => $this->target, 'key' => $this->key];
    }

    public function databaseType(): string
    {
        return $this->kind->value;
    }
}
