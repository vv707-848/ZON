<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\AppConfig;
use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forced update (426 UPDATE_REQUIRED) and maintenance mode (503 MAINTENANCE_MODE) of the app, per platform,
 * from the admin panel («إصدار التطبيق ووضع الصيانة»). The app sends X-App-Platform and X-App-Version on every
 * call. Test accounts pass maintenance; /app/config and provider webhooks are always open.
 */
class CheckAppVersion
{
    private const ALWAYS_OPEN = ['api/v1/app/config', 'api/v1/payments/webhook/*'];

    public function handle(Request $request, Closure $next): Response
    {
        $platform = strtolower((string) $request->header('X-App-Platform'));
        $config = in_array($platform, ['ios', 'android'], true) ? AppConfig::for($platform) : null;
        if ($config === null || $request->is(...self::ALWAYS_OPEN)) {
            return $next($request);
        }

        $version = (string) $request->header('X-App-Version');
        if ($version !== '' && version_compare($version, $config->min_version, '<')) {
            throw ApiException::of(ErrorCode::UpdateRequired, [
                'min_version' => $config->min_version,
                'latest_version' => $config->latest_version,
                'store_url' => $config->store_url,
            ]);
        }

        if ($config->inMaintenance()) {
            $user = $request->user('api');
            if (! ($user instanceof Customer && $user->is_tester)) {
                throw ApiException::of(ErrorCode::MaintenanceMode, [
                    'until' => $config->maintenance_until?->toIso8601String(),
                ], $config->maintenance_message ?: null);
            }
        }

        return $next($request);
    }
}
