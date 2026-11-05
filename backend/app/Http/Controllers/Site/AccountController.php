<?php

namespace App\Http\Controllers\Site;

use App\Actions\Accounts\RequestDeletion;
use App\Actions\Accounts\SignIn;
use App\Enums\AccountStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Billing\Entitlements;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use App\Support\Site\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The account on the website: its plan, and «حذف الحساب» — Google Play's rule for apps with accounts: a web page
 * where the deletion is requested without the app, naming the app, saying what is deleted and what is kept
 * (support.google.com/googleplay/android-developer/answer/13327111). The request is the app's own
 * (RequestDeletion): everything public hides at once, the account is purged after the grace period, and signing in
 * meanwhile cancels it. The page asks for the password (or the username/phone and password when signed out) and
 * an explicit confirmation; it never signs anyone in, so it never cancels a pending request by itself.
 */
class AccountController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $customer = $this->signedIn($request);
        if ($customer === null) {
            return redirect()->to(Site::url('login'));
        }
        $plan = Entitlements::for($customer);

        return view('site.account.show', ['customer' => $customer, 'plan' => $plan->package(), 'subscription' => $plan->subscription()]);
    }

    public function deletion(Request $request): View
    {
        return view('site.account.delete', [
            'customer' => $this->signedIn($request),
            'graceDays' => Settings::int(Setting::DeletionGraceDays),
            'done' => session('deletion'),
        ]);
    }

    public function delete(Request $request, SignIn $signIn, RequestDeletion $delete): RedirectResponse
    {
        $me = $this->signedIn($request);
        $data = $request->validate([
            'login' => [$me ? 'nullable' : 'required', 'string', 'max:80'],
            'password' => ['required', 'string', 'max:200'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'أكّد أنك تريد حذف الحساب.']);

        try {
            $customer = $me !== null
                ? (Hash::check($data['password'], $me->password) ? $me : throw ValidationException::withMessages(['password' => 'كلمة السر غير صحيحة.']))
                : $signIn->check((string) $data['login'], $data['password']);
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['login' => $e->getMessage()]);
        }

        $pending = $customer->pendingDeletion()->first();
        $deletion = $pending ?? $delete($customer);
        if ($me !== null) {
            Auth::guard('site')->logout();
            $request->session()->regenerate();
        }

        return redirect()->to(Site::url('account/delete'))->with('deletion', [
            'purge_at' => $deletion->purge_at->toIso8601String(),
            'already' => $pending !== null,
        ]);
    }

    /** The signed-in account while it may still use the website (a suspended or deleted one is signed out). */
    private function signedIn(Request $request): ?Customer
    {
        $customer = Auth::guard('site')->user();
        if (! $customer instanceof Customer) {
            return null;
        }
        if ($customer->status === AccountStatus::Suspended || $customer->anonymized_at !== null) {
            Auth::guard('site')->logout();

            return null;
        }

        return $customer;
    }
}
