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
 * and a push to the account's phones. A notice tied to a switch is not sent when it is off; a promotion goes to the
 * bell always and to the phones only with «العروض والتسويق» on.
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
        public readonly bool $promotion = false,
    ) {
        $this->onQueue('notify');
    }

    /** @return list<string> */
    public function via(Customer $notifiable): array
    {
        if ($notifiable->anonymized_at !== null || ($this->pref !== null && ! $notifiable->wants($this->pref))) {
            return [];
        }

        if ($this->promotion && ! $notifiable->wants(NoticePref::Marketing)) {
            return ['database'];
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
