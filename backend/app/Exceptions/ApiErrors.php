<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;
use App\Integrations\IntegrationException;
use App\Support\Api;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Every failure of /api/v1 in the one error envelope `{success:false, error:{code, message, details, trace_id}}`.
 * Unexpected errors say nothing about their cause: the trace id finds the log line («تتبّع طلب» in the panel).
 */
final class ApiErrors
{
    public static function render(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => Api::error($e->error, $e->details, $e->getMessage()),
            $e instanceof IntegrationException => Api::error(ErrorCode::IntegrationFailed),
            $e instanceof ValidationException => Api::error(ErrorCode::ValidationFailed, ['fields' => $e->errors()]),
            $e instanceof AuthenticationException => Api::error(ErrorCode::Unauthenticated),
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => Api::error(ErrorCode::RoleForbidden),
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => Api::error(ErrorCode::NotFound),
            $e instanceof ThrottleRequestsException => Api::error(ErrorCode::TooManyRequests)->withHeaders($e->getHeaders()),
            $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500 => Api::error(ErrorCode::NotFound, status: $e->getStatusCode()),
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 503 => Api::error(ErrorCode::MaintenanceMode),
            default => Api::error(ErrorCode::ServerError),
        };
    }
}
