<?php

namespace App\Http\Requests\Api\Auth;

use App\Http\Requests\Api\ApiRequest;

class LoginRequest extends ApiRequest
{
    use DeviceFields;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['login' => ['required', 'string', 'max:64'], 'password' => ['required', 'string', 'max:200'], ...$this->deviceRules()];
    }
}
