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
use App\Integrations\Nafath\NafathAnswer;
use App\Integrations\Nafath\NafathGateway;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;

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
        activity('accounts')->performedOn($me)->causedBy($me)->event('profile_updated')
            ->withProperties(['fields' => array_keys($request->validated())])->log('تعديل معلوماتي');

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

    /** The switches of the app's settings (the six, and «العروض والتسويق»); a missing one keeps its value. */
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
        $session = $request->user('api')->currentAccessToken();
        IssueToken::registerDevice($request->user('api'), $data, $session instanceof PersonalAccessToken ? $session->getKey() : null);

        return Api::ok();
    }

    /** The account's sessions: one per phone signed in, the newest use first, this phone marked. */
    public function sessions(Request $request): JsonResponse
    {
        $me = $request->user('api');
        $current = $me->currentAccessToken();
        $devices = $me->devices()->whereNotNull('access_token_id')->get()->keyBy('access_token_id');

        return Api::ok($me->tokens()->orderByDesc('last_used_at')->orderByDesc('id')->get()->map(fn (PersonalAccessToken $token) => [
            'id' => $token->getKey(),
            'device' => $token->name,
            'platform' => $devices->get($token->getKey())?->platform,
            'app_version' => $devices->get($token->getKey())?->app_version,
            'signed_in_at' => $token->created_at?->toIso8601String(),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'current' => $current instanceof PersonalAccessToken && $current->is($token),
        ])->values());
    }

    /** Ends one session — a lost phone's, or this one (= sign out): its token and its push registration go. */
    public function endSession(Request $request, int $session): JsonResponse
    {
        $token = $request->user('api')->tokens()->whereKey($session)->first() ?? throw ApiException::of(ErrorCode::NotFound);
        $token->delete();

        return Api::ok();
    }

    /**
     * Nafath, step 1: the app shows `random_number`; the person picks it in the Nafath app before `expires_at`.
     * One request at a time: asking again while it lives answers the same request (Nafath allows one per identity).
     */
    public function startNafath(Request $request, NafathGateway $nafath): JsonResponse
    {
        $data = $request->validate(['national_id' => ['required', 'regex:/^[12]\d{9}$/']]);
        $me = $request->user('api');
        if ($me->nafath_status === NafathStatus::Verified) {
            throw ApiException::of(ErrorCode::AlreadyActive);
        }
        $live = Cache::get("nafath.tx.{$me->id}");
        if (! is_array($live) || $me->nafath_status !== NafathStatus::Pending) {
            $challenge = $nafath->start($data['national_id']);
            $seconds = (int) config('zon.nafath.request_seconds');
            $live = ['transaction' => $challenge->transactionId, 'random' => $challenge->randomNumber, 'expires_at' => now()->addSeconds($seconds)->toIso8601String()];
            Cache::put("nafath.tx.{$me->id}", $live, now()->addSeconds($seconds));
            $me->forceFill(['nafath_status' => NafathStatus::Pending])->save();
        }

        return Api::ok(['transaction_id' => $live['transaction'], 'random_number' => $live['random'], 'expires_at' => $live['expires_at']]);
    }

    /**
     * Nafath, step 2: the app polls. `attempt` says where the request stands: waiting · verified · rejected ·
     * expired (the account is back to «none» and may start again).
     */
    public function nafathStatus(Request $request, NafathGateway $nafath): JsonResponse
    {
        $me = $request->user('api');
        $live = Cache::get("nafath.tx.{$me->id}");
        if ($me->nafath_status !== NafathStatus::Pending) {
            return Api::ok(['status' => $me->nafath_status->value, 'attempt' => null]);
        }

        $answer = is_array($live) ? $nafath->status($live['transaction']) : NafathAnswer::Expired;
        if ($answer === NafathAnswer::Waiting) {
            return Api::ok(['status' => 'pending', 'attempt' => 'waiting', 'random_number' => $live['random'], 'expires_at' => $live['expires_at']]);
        }
        $status = match ($answer) {
            NafathAnswer::Completed => NafathStatus::Verified,
            NafathAnswer::Rejected => NafathStatus::Rejected,
            default => NafathStatus::None,
        };
        $me->forceFill(['nafath_status' => $status, 'nafath_verified_at' => $status === NafathStatus::Verified ? now() : null])->save();
        Cache::forget("nafath.tx.{$me->id}");
        activity('accounts')->performedOn($me)->causedBy($me)->event('nafath')->withProperties(['status' => $answer->value])->log('التحقق عبر نفاذ');

        return Api::ok(['status' => $status->value, 'attempt' => $answer === NafathAnswer::Completed ? 'verified' : $answer->value]);
    }
}
