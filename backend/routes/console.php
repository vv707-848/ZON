<?php

use App\Jobs\Scheduled\AutoPublishPendingReviews;
use App\Jobs\Scheduled\ComputeDailyAreaStats;
use App\Jobs\Scheduled\ComputeDailyMetrics;
use App\Jobs\Scheduled\EndBoosts;
use App\Jobs\Scheduled\ExpireKnowledgeReviews;
use App\Jobs\Scheduled\ExpireLicences;
use App\Jobs\Scheduled\InactivateStaleListings;
use App\Jobs\Scheduled\NotifyWishMatches;
use App\Jobs\Scheduled\PruneOrphanMedia;
use App\Jobs\Scheduled\PurgeDueAccounts;
use App\Jobs\Scheduled\RecheckBrokerLicenses;
use App\Jobs\Scheduled\RemindLicenceExpiry;
use App\Jobs\Scheduled\RemindPendingReviews;
use App\Jobs\Scheduled\RemindSubscriptionRenewals;
use App\Jobs\Scheduled\ResumePausedListings;
use App\Jobs\Scheduled\RetryPendingIndexEvents;
use App\Jobs\Scheduled\SchedulerHeartbeat;
use App\Jobs\Scheduled\SendTaskReminders;
use App\Jobs\Scheduled\SyncOwnerContracts;
use Illuminate\Support\Facades\Schedule;

/*
| The schedule (cron runs `php artisan schedule:run` every minute — deploy/cron/zon). Riyadh time; each job runs on
| one server only and is idempotent. tests/Unit/ArchitectureTest checks every class of app/Jobs/Scheduled is here.
*/

Schedule::useCache('redis');

$at = fn ($event) => $event->timezone('Asia/Riyadh')->onOneServer();

// Health
$at(Schedule::job(new SchedulerHeartbeat)->everyMinute());

// The listing life cycle
$at(Schedule::job(new RemindPendingReviews)->hourly());
$at(Schedule::job(new AutoPublishPendingReviews)->hourlyAt(5));
$at(Schedule::job(new ResumePausedListings)->everyFifteenMinutes());
$at(Schedule::job(new InactivateStaleListings)->dailyAt('03:30'));
$at(Schedule::job(new EndBoosts)->hourlyAt(10));

// The search index
$at(Schedule::job(new RetryPendingIndexEvents)->everyFifteenMinutes());
$at(Schedule::job(new ExpireKnowledgeReviews)->dailyAt('00:30'));

// Users
$at(Schedule::job(new SendTaskReminders)->everyFiveMinutes());
$at(Schedule::job(new NotifyWishMatches)->twiceDaily(9, 18));
$at(Schedule::job(new RemindSubscriptionRenewals)->dailyAt('10:00'));

// REGA — a licence ends at midnight in Riyadh (Listing::live); the job records it five minutes later.
$at(Schedule::job(new ExpireLicences)->dailyAt('00:05'));
$at(Schedule::job(new RemindLicenceExpiry)->dailyAt('10:00'));
$at(Schedule::job(new SyncOwnerContracts)->everyTenMinutes());
$at(Schedule::job(new RecheckBrokerLicenses)->dailyAt('04:00'));

// Statistics
$at(Schedule::job(new ComputeDailyMetrics)->dailyAt('00:15'));
$at(Schedule::job(new ComputeDailyAreaStats)->dailyAt('01:00'));

// Privacy and housekeeping
$at(Schedule::job(new PurgeDueAccounts)->dailyAt('02:30'));
$at(Schedule::job(new PruneOrphanMedia)->dailyAt('03:00'));
$at(Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:15'));
$at(Schedule::command('queue:prune-failed --hours=168')->weekly());
$at(Schedule::command('activitylog:clean')->monthly());
