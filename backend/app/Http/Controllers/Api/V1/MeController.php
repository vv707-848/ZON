<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Accounts\ChangePhone;
use App\Actions\Accounts\IssueToken;
use App\Enums\ErrorCode;
use App\Enums\NafathStatus;
use App\Enums\NoticePref;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\PhoneRequest;
use App\Http\Requests\Api\Me\UpdateProfileRequest;
use App\Http\Resources\MeResource;
use App\Integrations\Nafath\NafathGateway;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** The signed-in account: profile, phone, password, notification switches, push token, Nafath. */
class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return Api::ok(new MeResource($request->user('api')));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $me = $request->customer();
        $me->fill($request->validated())->save();
        activity('accounts')->performedOn($me)->causedBy($me)->event('profile_updated')->withProperties(array_keys($request->validated()))->log('تعديل معلوماتي');

        return Api::ok(new MeResource($me));
    }

    public function changePhone(PhoneRequest $request, ChangePhone $change): JsonResponse
    {
        $request->validate(['phone' => [Rule::unique('customers', 'phone')]]);
        $change->start($request->customer(), $request->string('phone')->value());

        return Api::ok(['expires_in' => (int) config('zon.otp.ttl_minutes') * 60], status: 202);
    }

    public function verifyPhone(PhoneRequest $request, ChangePhone $change): JsonResponse
    {
        $request->validate(['code' => ['required']]);
        $change->finish($request->customer(), $request->string('phone')->value(), $request->string('code')->value());

        return Api::ok(new MeResource($request->customer()));
    }

    public function changePassword(Request $request): JsonResponse
    {
        $me = $request->user('api');
        $data = $request->validate(['current_password' => ['required', 'string'], 'password' => ['required', 'string', Password::defaults()]]);
        if (! Hash::check($data['current_password'], $me->password)) {
            throw ApiException::of(ErrorCode::ValidationFailed, ['fields' => ['current_password' => [__('validation.current_password')]]]);
        }
        $me->forceFill(['password' => $data['password']])->save();
        $me->tokens()->where('id', '!=', $me->currentAccessToken()->id)->delete();   // other phones sign in again

        return Api::ok();
    }

    /** The six switches of the app's settings; a missing one keeps its value. */
    public function notificationPrefs(Request $request): JsonResponse
    {
        $me = $request->user('api');
        $rules = [];
        foreach (NoticePref::values() as $pref) {
            $rules[$pref] = ['sometimes', 'boolean'];
        }
        $me->forceFill(['notification_prefs' => [...$me->noticePrefs(), ...array_map('boolval', $request->validate($rules))]])->save();

        return Api::ok($me->noticePrefs());
    }

    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'push_token' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'in:ios,android'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ]);
        IssueToken::registerDevice($request->user('api'), $data);

        return Api::ok();
    }

    /** Nafath, step 1: the app shows `random_number`; the person picks it in the Nafath app. */
    public function startNafath(Request $request, NafathGateway $nafath): JsonResponse
    {
        $data = $request->validate(['national_id' => ['required', 'regex:/^[12]\d{9}$/']]);
        $me = $request->user('api');
        if ($me->nafath_status === NafathStatus::Verified) {
            throw ApiException::of(ErrorCode::AlreadyActive);
        }
        $challenge = $nafath->start($data['national_id']);
        Cache::put("nafath.tx.{$me->id}", $challenge->transactionId, now()->addMinutes(10));
        $me->forceFill(['nafath_status' => NafathStatus::Pending])->save();

        return Api::ok(['transaction_id' => $challenge->transactionId, 'random_number' => $challenge->randomNumber]);
    }

    /** Nafath, step 2: the app polls until verified or rejected. */
    public function nafathStatus(Request $request, NafathGateway $nafath): JsonResponse
    {
        $me = $request->user('api');
        $transaction = Cache::get("nafath.tx.{$me->id}");
        if (is_string($transaction) && $me->nafath_status === NafathStatus::Pending) {
            $status = $nafath->status($transaction);
            if ($status !== NafathStatus::None && $status !== NafathStatus::Pending) {
                $me->forceFill(['nafath_status' => $status, 'nafath_verified_at' => $status === NafathStatus::Verified ? now() : null])->save();
                Cache::forget("nafath.tx.{$me->id}");
                activity('accounts')->performedOn($me)->causedBy($me)->event('nafath')->withProperties(['status' => $status->value])->log('التحقق عبر نفاذ');
            }
        }

        return Api::ok(['status' => $me->nafath_status->value]);
    }
}
