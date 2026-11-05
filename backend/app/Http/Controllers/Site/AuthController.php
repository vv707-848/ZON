<?php

namespace App\Http\Controllers\Site;

use App\Actions\Accounts\ResetPassword;
use App\Actions\Accounts\SignIn;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Support\Phone;
use App\Support\Site\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Signing in on the website with the app's account (username or phone, and password — the app's own rule), the
 * forgotten password by SMS code, signing out. Accounts are created in the app. Signing in cancels a pending
 * deletion, as in the app (store rule), and says so.
 */
class AuthController extends Controller
{
    public function form(): View|RedirectResponse
    {
        return Auth::guard('site')->check() ? redirect()->to(Site::url('account')) : view('site.auth.login');
    }

    public function login(Request $request, SignIn $signIn): RedirectResponse
    {
        $data = $request->validate(['login' => ['required', 'string', 'max:80'], 'password' => ['required', 'string', 'max:200']]);
        try {
            $customer = $signIn->check($data['login'], $data['password']);
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['login' => $e->getMessage()]);
        }
        $cancelled = $customer->pendingDeletion()->exists();
        $signIn->complete($customer);

        Auth::guard('site')->login($customer);
        $request->session()->regenerate();

        return redirect()->intended(Site::url('account'))
            ->with('status', $cancelled ? 'أُلغي طلب حذف حسابك لأنك سجّلت الدخول، وعادت عروضك كما كانت.' : null);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('site')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(Site::url());
    }

    public function forgotForm(): View
    {
        return view('site.auth.forgot');
    }

    /** A code to the phone when it has an account; the same answer either way (no account is revealed). */
    public function forgot(Request $request, ResetPassword $reset): RedirectResponse
    {
        $request->merge(['phone' => Phone::normalize((string) $request->input('phone')) ?? $request->input('phone')]);
        $phone = (string) $request->validate(['phone' => ['required', 'regex:/^\+9665\d{8}$/']], ['phone.regex' => 'رقم جوال سعودي يبدأ بـ 05.'])['phone'];
        $reset->start($phone);
        $request->session()->put('reset_phone', $phone);

        return redirect()->to(Site::url('password/reset'));
    }

    public function resetForm(Request $request): View|RedirectResponse
    {
        $phone = $request->session()->get('reset_phone');

        return is_string($phone) ? view('site.auth.reset', ['phone' => Phone::local($phone)]) : redirect()->to(Site::url('password/forgot'));
    }

    public function reset(Request $request, ResetPassword $reset): RedirectResponse
    {
        $phone = (string) $request->session()->get('reset_phone', '');
        $data = $request->validate(['code' => ['required', 'string', 'max:10'], 'password' => ['required', 'confirmed', Password::defaults()]]);
        try {
            $reset->finish($phone, trim($data['code']), $data['password']);
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }
        $request->session()->forget('reset_phone');

        return redirect()->to(Site::url('login'))->with('status', 'تغيّرت كلمة السر. سجّل الدخول بها، وسجّل الدخول من جديد في التطبيق أيضاً.');
    }
}
