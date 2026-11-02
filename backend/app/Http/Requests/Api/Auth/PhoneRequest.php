<?php

namespace App\Http\Requests\Api\Auth;

use App\Http\Requests\Api\ApiRequest;
use Illuminate\Validation\Rules\Password;

/** Every form made of a phone, maybe a code, maybe a new password (forgot / reset / resend / change phone). */
class PhoneRequest extends ApiRequest
{
    protected array $phones = ['phone'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'regex:/^\+9665\d{8}$/'],
            'code' => ['sometimes', 'required', 'string', 'max:10'],
            'password' => ['sometimes', 'required', 'string', Password::defaults()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['phone.regex' => __('validation.phone')];
    }
}
