<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends PhoneRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...parent::rules(), 'code' => ['required', 'string', 'max:10'], 'password' => ['required', 'string', Password::defaults()]];
    }
}
