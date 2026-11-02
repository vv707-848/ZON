<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Accounts\CancelDeletion;
use App\Actions\Accounts\RequestDeletion;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\LegacyClaim;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/** «حذف حسابي» and «طلب استعادة بيانات الإصدار السابق». */
class AccountController extends Controller
{
    public function requestDeletion(Request $request, RequestDeletion $delete): JsonResponse
    {
        $me = $request->user('api');
        $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check($request->string('password')->value(), $me->password)) {
            throw ApiException::of(ErrorCode::ValidationFailed, ['fields' => ['password' => [__('validation.current_password')]]]);
        }
        if ($me->pendingDeletion()->exists()) {
            throw ApiException::of(ErrorCode::AlreadyActive);
        }
        $deletion = $delete($me);

        return Api::ok(['purge_at' => $deletion->purge_at->toIso8601String()], status: 202);
    }

    /** Cancelling from a phone still signed in (signing in again also cancels). */
    public function cancelDeletion(Request $request, CancelDeletion $cancel): JsonResponse
    {
        $me = $request->user('api');
        $deletion = $me->pendingDeletion()->first() ?? throw ApiException::of(ErrorCode::NotFound);
        $cancel($deletion, "customer:{$me->id}");

        return Api::ok();
    }

    /**
     * The ids app 1.1 saved on this phone without an owner. Staff review the claim; nothing is given automatically
     * (and never because a name looks alike).
     */
    public function legacyClaim(Request $request): JsonResponse
    {
        $data = $request->validate([
            'listings' => ['array', 'max:200'],
            'listings.*' => ['string', 'max:40'],
            'requests' => ['array', 'max:200'],
            'requests.*' => ['string', 'max:40'],
        ]);
        if (empty($data['listings']) && empty($data['requests'])) {
            throw ApiException::of(ErrorCode::ValidationFailed, ['fields' => ['listings' => [__('validation.required', ['attribute' => 'البيانات'])]]]);
        }
        $claim = LegacyClaim::query()->create(['customer_id' => $request->user('api')->id, 'items' => $data, 'status' => 'pending']);

        return Api::created(['id' => $claim->id, 'status' => $claim->status]);
    }
}
