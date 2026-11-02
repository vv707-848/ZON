<?php

namespace App\Http\Requests\Api\Auth;

use App\Enums\CustomerRole;
use App\Http\Requests\Api\ApiRequest;
use App\Rules\KnownCity;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** «حساب جديد» — the app's sign-up form (login_screen.dart), the same rules as LocalAuth. */
class RegisterRequest extends ApiRequest
{
    protected array $phones = ['phone'];

    protected array $numeric = ['fal_number'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(CustomerRole::class)],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'regex:/^\+9665\d{8}$/', Rule::unique('customers', 'phone')],
            'city' => ['required', 'string', new KnownCity],
            'username' => ['required', 'regex:/^[A-Za-z0-9._-]{3,32}$/', Rule::unique('customers', 'username')],
            'password' => ['required', 'string', Password::defaults()],
            'fal_number' => ['nullable', Rule::requiredIf($this->input('role') === CustomerRole::Broker->value), 'regex:/^\d{6,12}$/'],
            'device_id' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['phone.regex' => __('validation.phone'), 'username.regex' => __('validation.username')];
    }
}
