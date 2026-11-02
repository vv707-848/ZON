<?php

namespace App\Jobs\Scheduled;

use App\Jobs\ScheduledJob;
use App\Models\Task;
use App\Notifications\Notices;

/** «مهامي»: a reminder at its time, once (reminded_at), never for a task already done. */
class SendTaskReminders extends ScheduledJob
{
    public function __construct()
    {
        $this->onQueue('notify');
    }

    public function handle(): void
    {
        Task::query()->with('customer')->whereNull('done_at')->whereNull('reminded_at')->whereNotNull('remind_at')->where('remind_at', '<=', now())
            ->chunkById(500, function ($tasks) {
                foreach ($tasks as $task) {
                    $task->forceFill(['reminded_at' => now()])->save();
                    $task->customer->notify(Notices::taskReminder($task));
                }
            });
    }
}
