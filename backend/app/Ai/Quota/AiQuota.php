<?php

namespace App\Ai\Quota;

use App\Models\Customer;
use App\Models\Package;
use App\Services\Billing\Entitlements;
use App\Support\Fingerprint;
use Carbon\CarbonImmutable;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

/**
 * Daily AI caps on three layers (§10 «الحدود»): the account (the plan's cap), its device (×1.5) and its IP (×3),
 * so a cap cannot be multiplied by opening accounts. One Lua script reserves on all layers at once or on none;
 * the reservation is released unless AiGateway got a full answer (consume()). Days follow Riyadh time.
 */
final class AiQuota
{
    public const USES = ['search', 'matching', 'summary', 'chat'];

    private const RESERVE = <<<'LUA'
        for i, key in ipairs(KEYS) do
            if tonumber(redis.call('GET', key) or '0') >= tonumber(ARGV[i]) then return 0 end
        end
        for i, key in ipairs(KEYS) do
            redis.call('INCR', key)
            redis.call('EXPIRE', key, tonumber(ARGV[#KEYS + 1]))
        end
        return 1
        LUA;

    private static bool $consumed = false;

    /** Reserves one call; null when a layer is full. */
    public function reserve(string $use, Customer $customer, ?string $deviceId, ?string $ip, int $cap): ?Reservation
    {
        $keys = $this->keys($use, $customer, $deviceId, $ip);
        $limits = [];
        foreach (array_keys($keys) as $layer) {
            $limits[] = (int) ceil($cap * (float) config("zon.ai.quota_multipliers.{$layer}"));
        }

        $ok = $this->redis()->eval(self::RESERVE, count($keys), ...array_values($keys), ...$limits, ...[2 * 86400]);
        if ((int) $ok !== 1) {
            return null;
        }
        self::$consumed = false;

        return new Reservation($this->redis(), array_values($keys), $cap - (int) $this->redis()->get($keys['account']));
    }

    /**
     * Every try counts here, answered or not. A failed or rejected answer gives its reservation back (the user
     * is not charged for it), so without this a request built to fail could call the model without end.
     * Allowed: attempts_multiplier × the daily cap, per account and use.
     */
    public function attempt(string $use, Customer $customer, int $cap): bool
    {
        $key = 'aiq:'.$use.':'.CarbonImmutable::now('Asia/Riyadh')->format('Ymd').':t:'.$customer->id;
        $tries = (int) $this->redis()->incr($key);
        if ($tries === 1) {
            $this->redis()->expire($key, 2 * 86400);
        }

        return $tries <= $cap * (int) config('zon.ai.attempts_multiplier');
    }

    public function used(string $use, Customer $customer): int
    {
        return (int) $this->redis()->get($this->keys($use, $customer, null, null)['account']);
    }

    /** Called by AiGateway on a full answer: the reservation is kept. */
    public static function consume(): void
    {
        self::$consumed = true;
    }

    public static function consumed(): bool
    {
        return self::$consumed;
    }

    public static function resetsAt(): CarbonImmutable
    {
        return CarbonImmutable::now('Asia/Riyadh')->addDay()->startOfDay();
    }

    /**
     * The plans that would give more of this use (sent with 402 / 429).
     *
     * @return list<array{package: string, name: string, price: string, daily: int}>
     */
    public static function offers(Customer $customer, string $use): array
    {
        $current = Entitlements::for($customer)->aiDaily($use);

        return Package::query()->where('audience', $customer->role->value)->where('is_active', true)
            ->where('price_halalas', '>', 0)->where("ai_{$use}_daily", '>', $current)->orderBy('price_halalas')->get()
            ->map(fn (Package $p) => ['package' => $p->code, 'name' => $p->name, 'price' => $p->price()->riyals(), 'daily' => (int) $p->getAttribute("ai_{$use}_daily")])
            ->all();
    }

    /** @return array<string, string> layer => counter key */
    private function keys(string $use, Customer $customer, ?string $deviceId, ?string $ip): array
    {
        $day = CarbonImmutable::now('Asia/Riyadh')->format('Ymd');

        return array_filter([
            'account' => "aiq:{$use}:{$day}:a:{$customer->id}",
            'device' => ($device = Fingerprint::of($deviceId)) ? "aiq:{$use}:{$day}:d:{$device}" : null,
            'ip' => ($address = Fingerprint::of($ip)) ? "aiq:{$use}:{$day}:i:{$address}" : null,
        ]);
    }

    /** Quotas use phpredis (REDIS_CLIENT=phpredis — deploy/README.md); its eval() takes the keys count first. */
    private function redis(): PhpRedisConnection
    {
        $connection = Redis::connection((string) config('zon.ai.quota_connection'));

        return $connection instanceof PhpRedisConnection ? $connection : throw new RuntimeException('AI quotas need REDIS_CLIENT=phpredis');
    }
}
