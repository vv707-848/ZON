<?php

namespace App\Console\Commands;

use App\Enums\HealthStatus;
use App\Support\Health\CheckResult;
use App\Support\Health\SystemHealth;
use Illuminate\Console\Command;

/**
 * `php artisan zon:doctor` — is everything the backend depends on working, and where to look
 * when it is not (App\Support\Health\SystemHealth; the admin page «صحة النظام» shows the same).
 * Exits 1 when a check fails — warnings exit 0 — so monitoring can run it every few minutes.
 */
class ZonDoctor extends Command
{
    protected $signature = 'zon:doctor {--json : JSON output for monitoring}';

    protected $description = 'Checks the database, Redis, queues, scheduler, agents service, search index, storage, websockets and integrations';

    public function handle(SystemHealth $health): int
    {
        $results = $health->run();

        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(fn (CheckResult $r) => $r->toArray(), $results), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $this->table(['', 'الفحص', 'النتيجة', 'أين تبحث'], array_map(
                fn (CheckResult $r) => [$r->status->symbol(), $r->label, $r->detail, $r->hint ?? ''],
                $results,
            ));
        }

        $failed = array_filter($results, fn (CheckResult $r) => $r->status === HealthStatus::Fail);

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
