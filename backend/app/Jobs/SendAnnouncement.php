<?php

namespace App\Jobs;

use App\Enums\AccountStatus;
use App\Models\Announcement;
use App\Models\Customer;
use App\Notifications\Notices;
use App\Notifications\PushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A staff announcement to every active account, a role, and/or a city — once (sent_at), counted: `recipients` got
 * the bell notice, `pushed` the push too (all of them for a service message, those who agreed for a promotion).
 */
class SendAnnouncement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public function __construct(public readonly int $announcementId)
    {
        $this->onQueue('notify');
    }

    public function handle(): void
    {
        $announcement = Announcement::query()->find($this->announcementId);
        if ($announcement === null || $announcement->sent_at !== null) {
            return;
        }
        $announcement->forceFill(['sent_at' => now()])->save();

        [$count, $pushed] = [0, 0];
        Customer::query()->where('status', AccountStatus::Active)->whereNull('anonymized_at')
            ->when($announcement->audience !== 'all', fn ($q) => $q->where('role', $announcement->audience))
            ->when($announcement->city, fn ($q, $city) => $q->where('city', $city))
            ->chunkById(500, function ($customers) use ($announcement, &$count, &$pushed) {
                foreach ($customers as $customer) {
                    $notice = Notices::announcement($announcement->title, $announcement->body, $announcement->target, $announcement->kind);
                    $customer->notify($notice);
                    $count++;
                    $pushed += (int) in_array(PushChannel::class, $notice->via($customer), true);
                }
            });
        $announcement->forceFill(['recipients' => $count, 'pushed' => $pushed])->save();
    }
}
