<?php

namespace App\Support\Health;

use App\Enums\HealthStatus;
use App\Integrations\Drivers;
use App\Jobs\Scheduled\SchedulerHeartbeat;
use App\Models\IndexEvent;
use App\Support\Trace;
use Closure;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Everything the backend depends on, checked in one place — for `php artisan zon:doctor` and
 * the admin page «صحة النظام». Each check answers ok / warn / fail with a short detail and,
 * when it is not ok, where to look first (docs/operations.md is the full runbook).
 *
 * The backend joins three parties: the mobile app (API → database, Redis, queues, websockets,
 * external integrations), the admin panel (the same backend) and the AI layer (the agents
 * service, the search-index outbox, the SuperAgent's read-only connection). A check never
 * throws: an exception is reported as the failure of that check.
 */
final class SystemHealth
{
    /** Waiting jobs per queue from which the queue is reported (workers: deploy/supervisor). */
    public const QUEUE_WARN_AT = ['index' => 500, 'ai' => 100, 'notify' => 1000, 'media' => 200, 'default' => 500];

    /** The heartbeat is written every minute; older than this, the scheduler or the `default` worker stopped. */
    public const HEARTBEAT_STALE_SECONDS = 180;

    /** An index signal still unacknowledged after this long is late (RetryPendingIndexEvents resends after 5 minutes). */
    public const INDEX_LATE_MINUTES = 30;

    public function __construct(private readonly Migrator $migrator) {}

    /** @return list<CheckResult> */
    public function run(): array
    {
        return [
            $this->check('app', 'إعدادات الخادم', 'ملف .env: APP_KEY · APP_DEBUG · AGENTS_INTERNAL_KEY · INDEX_SHARED_SECRET · RATE_SALT · ADMIN_ALLOWED_IPS', $this->app(...)),
            $this->check('database', 'قاعدة البيانات', 'خدمة MySQL (systemctl status mysql) وقيم DB_* في .env', $this->database(...)),
            $this->check('migrations', 'ترحيلات قاعدة البيانات', 'php artisan migrate --force', $this->migrations(...)),
            $this->check('redis', 'Redis', 'خدمة Redis (systemctl status redis) وقيم REDIS_* في .env', $this->redis(...)),
            $this->check('scheduler', 'المجدول وعامل الطوابير', 'cron: * * * * * php artisan schedule:run — والعامل zon-worker-general (supervisorctl status)', $this->scheduler(...)),
            $this->check('queues', 'الطوابير', 'supervisorctl status — العمال zon-worker-index و zon-worker-ai و zon-worker-general', $this->queues(...)),
            $this->check('failed_jobs', 'المهام الفاشلة', 'php artisan queue:failed لمعرفة السبب، ثم queue:retry <id> بعد إصلاحه', $this->failedJobs(...)),
            $this->check('agents', 'خدمة الوكلاء (الذكاء)', 'supervisorctl status zon-agents — وسجلّها /var/log/zon/agents.log بنفس رقم التتبع', $this->agents(...)),
            $this->check('index', 'مزامنة الفهرس', 'لوحة الإدارة ← مراقبة الفهرسة (آخر خطأ وإعادة الإرسال) — والعامل zon-worker-index', $this->index(...)),
            $this->check('report_db', 'اتصال الوكيل الأعلى', 'deploy/mysql/ai_reader.sql وقيم REPORT_DB_* في .env', $this->reportDatabase(...)),
            $this->check('storage', 'تخزين الصور والمستندات', 'MEDIA_DISK وإعدادات MinIO (AWS_*) أو صلاحيات storage/app', $this->storage(...)),
            $this->check('uploads', 'رفع الصور ومعالجتها', 'deploy/php/zon.ini في conf.d لـ PHP-FPM ولسطر الأوامر، ثم إعادة تشغيل php8.5-fpm — وامتدادا gd و exif', $this->uploads(...)),
            $this->check('websockets', 'المراسلة الفورية (Reverb)', 'supervisorctl status zon-reverb — وقيم REVERB_* في .env', $this->websockets(...)),
            $this->check('integrations', 'التكاملات الخارجية', 'config/services.php و docs/integrations.md — قيم *_DRIVER و PRODUCTION_ALLOWS_FAKE', $this->integrations(...)),
            $this->check('errors', 'تتبّع الأخطاء (Sentry)', 'SENTRY_LARAVEL_DSN و SENTRY_RELEASE في .env — docs/operations.md «تتبّع الأخطاء»', $this->errors(...)),
        ];
    }

    /** @param Closure(): array{HealthStatus, string} $probe */
    private function check(string $key, string $label, string $hint, Closure $probe): CheckResult
    {
        try {
            [$status, $detail] = $probe();
        } catch (Throwable $e) {
            [$status, $detail] = [HealthStatus::Fail, Str::limit($e->getMessage(), 240)];
        }

        return new CheckResult($key, $label, $status, $detail, $status === HealthStatus::Ok ? null : $hint);
    }

    /** @return array{HealthStatus, string} */
    private function app(): array
    {
        $missing = array_keys(array_filter([
            'APP_KEY' => config('app.key'),
            'AGENTS_INTERNAL_KEY' => config('zon.agents.key'),
            'INDEX_SHARED_SECRET' => config('zon.index.secret'),
            'RATE_SALT' => config('zon.rate_salt'),
        ], fn ($value) => blank($value)));
        $problems = $missing === [] ? [] : ['قيم فارغة: '.implode('، ', $missing)];
        if (app()->isProduction() && config('app.debug')) {
            $problems[] = 'APP_DEBUG مفعّل في الإنتاج (يكشف تفاصيل الأخطاء للمستخدمين)';
        }
        if ($problems !== []) {
            return [HealthStatus::Fail, implode(' · ', $problems)];
        }
        if (app()->isProduction() && config('zon.admin_allowed_ips') === []) {
            return [HealthStatus::Warn, 'لوحة الإدارة مفتوحة لكل العناوين: ADMIN_ALLOWED_IPS فارغ'];
        }

        return [HealthStatus::Ok, 'البيئة '.app()->environment().' · التصحيح '.(config('app.debug') ? 'مفعّل' : 'معطّل')];
    }

    /** @return array{HealthStatus, string} */
    private function database(): array
    {
        $ms = $this->timed(fn () => DB::select('SELECT 1'));

        return [HealthStatus::Ok, DB::connection()->getDatabaseName()." · {$ms} ms"];
    }

    /** @return array{HealthStatus, string} */
    private function migrations(): array
    {
        if (! $this->migrator->repositoryExists()) {
            return [HealthStatus::Fail, 'جدول الترحيلات غير موجود: قاعدة بيانات لم تُرحَّل'];
        }
        $files = $this->migrator->getMigrationFiles([database_path('migrations'), ...$this->migrator->paths()]);
        $pending = array_values(array_diff(array_keys($files), $this->migrator->getRepository()->getRan()));

        return $pending === []
            ? [HealthStatus::Ok, count($files).' ترحيل مطبّق']
            : [HealthStatus::Fail, count($pending).' لم يُطبَّق: '.implode('، ', array_slice($pending, 0, 3))];
    }

    /** @return array{HealthStatus, string} */
    private function redis(): array
    {
        $timings = [];
        foreach (array_keys(array_diff_key(config('database.redis'), ['client' => 0, 'options' => 0])) as $connection) {
            try {
                $timings[] = $connection.' '.$this->timed(fn () => Redis::connection($connection)->ping()).' ms';
            } catch (Throwable $e) {
                return [HealthStatus::Fail, "الاتصال {$connection}: ".Str::limit($e->getMessage(), 200)];
            }
        }

        return [HealthStatus::Ok, implode(' · ', $timings)];
    }

    /** @return array{HealthStatus, string} */
    private function scheduler(): array
    {
        $beat = Cache::get(SchedulerHeartbeat::CACHE_KEY);
        if (! is_string($beat)) {
            return [HealthStatus::Fail, 'لم تصل أي نبضة: المجدول (cron) أو عامل الطابور default متوقف'];
        }
        $age = (int) Carbon::parse($beat)->diffInSeconds(now());

        return $age > self::HEARTBEAT_STALE_SECONDS
            ? [HealthStatus::Fail, 'آخر نبضة قبل '.intdiv($age, 60).' دقيقة: المجدول أو عامل الطابور default متوقف']
            : [HealthStatus::Ok, "آخر نبضة قبل {$age} ثانية"];
    }

    /** @return array{HealthStatus, string} */
    private function queues(): array
    {
        $connection = (string) config('queue.default');
        if ($connection === 'sync') {
            return [app()->isProduction() ? HealthStatus::Fail : HealthStatus::Ok, 'sync: تُنفَّذ المهام داخل الطلب نفسه (للتطوير فقط)'];
        }

        $sizes = [];
        $behind = [];
        foreach (self::QUEUE_WARN_AT as $queue => $warnAt) {
            $size = Queue::connection($connection)->size($queue);
            $sizes[] = "{$queue} {$size}";
            if ($size >= $warnAt) {
                $behind[] = $queue;
            }
        }
        $detail = 'بانتظار التنفيذ: '.implode(' · ', $sizes);

        return $behind === [] ? [HealthStatus::Ok, $detail] : [HealthStatus::Warn, 'متأخر: '.implode('، ', $behind).' — '.$detail];
    }

    /** @return array{HealthStatus, string} */
    private function failedJobs(): array
    {
        if (! in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true)) {
            return [HealthStatus::Ok, 'سجل المهام الفاشلة خارج قاعدة البيانات ('.config('queue.failed.driver').')'];
        }
        $recent = fn () => DB::connection(config('queue.failed.database'))->table((string) config('queue.failed.table'))
            ->where('failed_at', '>=', now()->subDay());

        $total = $recent()->count();
        if ($total === 0) {
            return [HealthStatus::Ok, 'لا شيء خلال 24 ساعة'];
        }
        $top = $recent()
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.displayName')) AS job, COUNT(*) AS failures")
            ->groupBy('job')->orderByDesc('failures')->limit(3)->get()
            ->map(fn (object $row) => class_basename((string) $row->job)." ({$row->failures})");

        return [HealthStatus::Warn, "{$total} خلال 24 ساعة — ".$top->implode('، ')];
    }

    /** @return array{HealthStatus, string} */
    private function agents(): array
    {
        $response = Http::acceptJson()
            ->withHeaders([Trace::HEADER => Trace::id()])
            ->connectTimeout(2)
            ->timeout(4)
            ->get(rtrim((string) config('zon.agents.url'), '/').'/agents/health');
        if (! $response->successful()) {
            return [HealthStatus::Fail, 'HTTP '.$response->status()];
        }

        $parts = ['llm' => 'النموذج', 'embed' => 'التضمين', 'qdrant' => 'Qdrant'];
        $down = array_filter($parts, fn (string $key) => $response->json($key) !== true, ARRAY_FILTER_USE_KEY);
        $detail = implode(' · ', array_map(fn (string $key, string $name) => $name.' '.(isset($down[$key]) ? '✗' : '✓'), array_keys($parts), $parts));
        $model = $response->json('model');
        if (is_string($model) && $model !== '') {
            $detail .= " · {$model}";                    // the model profile the AI node serves (agents' LLM_PROFILE)
        }
        if (is_bool($response->json('errors'))) {
            $detail .= ' · Sentry '.($response->json('errors') ? '✓' : '✗');   // the agents service's own SENTRY_DSN
        }
        if ($down === []) {
            return [HealthStatus::Ok, $detail];
        }

        return config('zon.agents.fallback_enabled')
            ? [HealthStatus::Warn, $detail.' — الطلبات تعمل بالمسار البديل (ترتيب SQL بلا تعليل)']
            : [HealthStatus::Fail, $detail.' — والمسار البديل معطّل (AI_FALLBACK_ENABLED)'];
    }

    /** @return array{HealthStatus, string} */
    private function index(): array
    {
        $failed = IndexEvent::query()->failed()->count();
        $late = IndexEvent::query()->pending()->where('created_at', '<', now()->subMinutes(self::INDEX_LATE_MINUTES));
        $lateCount = (clone $late)->count();
        $waiting = IndexEvent::query()->pending()->count();
        if ($failed === 0 && $lateCount === 0) {
            return [HealthStatus::Ok, "{$waiting} بانتظار التأكيد"];
        }
        $oldestLate = $lateCount > 0 ? $late->min('created_at') : null;

        return [HealthStatus::Warn, "فاشلة {$failed} · متأخرة أكثر من ".self::INDEX_LATE_MINUTES." دقيقة {$lateCount}"
            .($oldestLate !== null ? ' (أقدمها '.Carbon::parse((string) $oldestLate)->diffForHumans().')' : '')];
    }

    /**
     * The SuperAgent works through its own read-only connection; without it only the SuperAgent stops.
     *
     * @return array{HealthStatus, string}
     */
    private function reportDatabase(): array
    {
        $connection = (string) config('zon.report_connection');
        try {
            $ms = $this->timed(fn () => DB::connection($connection)->select('SELECT 1'));
        } catch (Throwable $e) {
            return [HealthStatus::Warn, 'الوكيل الأعلى متوقف: '.Str::limit($e->getMessage(), 200)];
        }

        return [HealthStatus::Ok, "{$connection} · {$ms} ms"];
    }

    /** @return array{HealthStatus, string} */
    private function storage(): array
    {
        $diskName = (string) config('zon.media_disk');
        $disk = Storage::disk($diskName);
        $path = 'health/'.Str::ulid().'.txt';
        $written = false;
        $ms = $this->timed(function () use ($disk, $path, &$written) {
            $written = $disk->put($path, 'ok');
            $disk->delete($path);
        });

        return $written
            ? [HealthStatus::Ok, "{$diskName} · كتابة وحذف {$ms} ms"]
            : [HealthStatus::Fail, "تعذّرت الكتابة على القرص {$diskName}"];
    }

    /**
     * Photos are decoded and redrawn at upload (App\Support\Photos\Photos): GD with JPEG, EXIF for the camera's
     * orientation, and PHP limits that let the largest accepted photo in (PHP's own default upload limit is 2 MB).
     * zon:doctor reads the command line's php.ini; the panel page reads the web server's.
     *
     * @return array{HealthStatus, string}
     */
    private function uploads(): array
    {
        $bytes = function (string $key): int {
            $value = trim((string) ini_get($key));
            $number = (int) $value;

            return match (strtolower(substr($value, -1))) {
                'g' => $number * 1024 ** 3,
                'm' => $number * 1024 ** 2,
                'k' => $number * 1024,
                default => $number,
            };
        };
        $needed = (int) config('zon.media.max_kb') * 1024;
        $memory = $bytes('memory_limit');
        $problems = array_filter([
            ! function_exists('imagecreatefromjpeg') ? 'امتداد gd بلا JPEG' : null,
            $bytes('upload_max_filesize') < $needed ? 'upload_max_filesize = '.ini_get('upload_max_filesize').' أقل من '.((int) config('zon.media.max_kb') / 1024).'M' : null,
            $bytes('post_max_size') < $needed ? 'post_max_size = '.ini_get('post_max_size').' أقل من حجم الصورة المسموح' : null,
        ]);
        if ($problems !== []) {
            // On the command line these are the CLI's values, not the web server's: a hint, not a failure.
            return PHP_SAPI === 'cli'
                ? [HealthStatus::Warn, 'قيم سطر الأوامر: '.implode(' · ', $problems)]
                : [HealthStatus::Fail, implode(' · ', $problems)];
        }
        $warnings = array_filter([
            ! function_exists('exif_read_data') ? 'امتداد exif غائب: صور الجوال قد تظهر مقلوبة' : null,
            $memory !== -1 && $memory < 256 * 1024 ** 2 ? 'memory_limit = '.ini_get('memory_limit').' أقل من 256M: الصور الكبيرة قد تفشل' : null,
        ]);

        return $warnings !== []
            ? [HealthStatus::Warn, implode(' · ', $warnings)]
            : [HealthStatus::Ok, 'حد الرفع '.ini_get('upload_max_filesize').' · الذاكرة '.ini_get('memory_limit').' · gd و exif'];
    }

    /** @return array{HealthStatus, string} */
    private function websockets(): array
    {
        if (config('broadcasting.default') !== 'reverb') {
            return [app()->isProduction() ? HealthStatus::Warn : HealthStatus::Ok, 'معطّلة: BROADCAST_CONNECTION='.config('broadcasting.default')];
        }
        $host = (string) config('reverb.servers.reverb.host');
        $host = $host === '0.0.0.0' ? '127.0.0.1' : $host;
        $port = (int) config('reverb.servers.reverb.port');
        $socket = @fsockopen($host, $port, $errorCode, $error, 2);
        if ($socket === false) {
            return [HealthStatus::Fail, "{$host}:{$port} لا يستجيب ({$error})"];
        }
        fclose($socket);

        return [HealthStatus::Ok, "{$host}:{$port}"];
    }

    /** @return array{HealthStatus, string} */
    private function integrations(): array
    {
        $drivers = [];
        foreach (array_keys(Drivers::SERVICES) as $service) {
            $drivers[$service] = Drivers::of($service);
        }
        $detail = implode(' · ', array_map(fn (string $service, string $driver) => "{$service}: {$driver}", array_keys($drivers), $drivers));
        $fakes = array_keys($drivers, Drivers::FAKE, true);
        if ($fakes === [] || ! app()->isProduction()) {
            return [HealthStatus::Ok, $detail];
        }

        $refused = array_values(array_filter($fakes, fn (string $service) => ! Drivers::fakeAllowedInProduction($service)));

        return $refused !== []
            ? [HealthStatus::Fail, 'محاكاة مرفوضة في الإنتاج: '.implode('، ', $refused).' — '.$detail]
            : [HealthStatus::Warn, 'محاكاة مسموحة مؤقتًا: '.implode('، ', $fakes).' — '.$detail];
    }

    /** @return array{HealthStatus, string} */
    private function errors(): array
    {
        if (blank(config('sentry.dsn'))) {
            return app()->isProduction()
                ? [HealthStatus::Warn, 'لا يصل أي خطأ لأحد: SENTRY_LARAVEL_DSN فارغ']
                : [HealthStatus::Ok, 'معطّل (بلا DSN) — اختياري خارج الإنتاج'];
        }
        $release = config('sentry.release');
        $detail = 'البيئة '.(config('sentry.environment') ?: app()->environment()).' · الإصدار '.($release ?: '—')
            .' · عيّنة الأداء '.(float) config('sentry.traces_sample_rate');

        return app()->isProduction() && blank($release)
            ? [HealthStatus::Warn, $detail.' — بلا رقم إصدار لا يُعرف أي نشر أدخل الخطأ']
            : [HealthStatus::Ok, $detail];
    }

    /** Milliseconds the callback took. */
    private function timed(Closure $callback): int
    {
        $started = hrtime(true);
        $callback();

        return (int) round((hrtime(true) - $started) / 1e6);
    }
}
