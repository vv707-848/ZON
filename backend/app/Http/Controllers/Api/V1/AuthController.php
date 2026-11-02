<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Accounts\CompleteRegistration;
use App\Actions\Accounts\IssueToken;
use App\Actions\Accounts\ResetPassword;
use App\Actions\Accounts\SignIn;
use App\Actions\Accounts\StartRegistration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Requests\Api\Auth\PhoneRequest;
use App\Http\Requests\Api\Auth\RegisterRequest;
use App\Http\Requests\Api\Auth\ResetPasswordRequest;
use App\Http\Requests\Api\Auth\VerifyRegistrationRequest;
use App\Http\Resources\MeResource;
use App\Support\Api;
use App\Support\Otp;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Sign-up (two steps), sign-in, forgotten password, sign-out — the app's LocalAuth, now on the server. */
class AuthController extends Controller
{
    public function register(RegisterRequest $request, StartRegistration $start): JsonResponse
    {
        $start($request->validated(), $request->input('device_id'), $request->ip());

        return Api::ok(['phone' => Phone::local($request->string('phone')->value()), 'expires_in' => (int) config('zon.otp.ttl_minutes') * 60], status: 202);
    }

    public function verify(VerifyRegistrationRequest $request, CompleteRegistration $complete, IssueToken $issue): JsonResponse
    {
        $customer = $complete($request->string('phone')->value(), $request->string('code')->value());

        return Api::created(['token' => $issue($customer, $request->device()), 'me' => new MeResource($customer)]);
    }

    /** Sends the code of a waiting sign-up again (throttled per number and IP). */
    public function resend(PhoneRequest $request, Otp $otp): JsonResponse
    {
        $phone = $request->string('phone')->value();
        if (Cache::has(StartRegistration::key($phone))) {
            $otp->send($phone, Otp::REGISTER);
        }

        return Api::ok(['expires_in' => (int) config('zon.otp.ttl_minutes') * 60]);
    }

    public function login(LoginRequest $request, SignIn $signIn, IssueToken $issue): JsonResponse
    {
        $customer = $signIn($request->string('login')->value(), $request->string('password')->value());

        return Api::ok(['token' => $issue($customer, $request->device()), 'me' => new MeResource($customer)]);
    }

    public function forgot(PhoneRequest $request, ResetPassword $reset): JsonResponse
    {
        $reset->start($request->string('phone')->value());

        return Api::ok(['expires_in' => (int) config('zon.otp.ttl_minutes') * 60]);   // the same answer for any number
    }

    public function reset(ResetPasswordRequest $request, ResetPassword $reset): JsonResponse
    {
        $reset->finish($request->string('phone')->value(), $request->string('code')->value(), $request->string('password')->value());

        return Api::ok();
    }

    public function logout(Request $request): JsonResponse
    {
        $customer = $request->user('api');
        $customer->currentAccessToken()->delete();
        if ($request->filled('push_token')) {
            $customer->devices()->where('push_token', $request->input('push_token'))->delete();
        }

        return Api::ok();
    }
}
