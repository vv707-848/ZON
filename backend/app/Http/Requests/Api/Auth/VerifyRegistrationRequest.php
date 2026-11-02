<?php

namespace App\Http\Requests\Api\Auth;

use App\Http\Requests\Api\ApiRequest;

class VerifyRegistrationRequest extends ApiRequest
{
    use DeviceFields;

    protected array $phones = ['phone'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['phone' => ['required', 'regex:/^\+9665\d{8}$/'], 'code' => ['required', 'string', 'max:10'], ...$this->deviceRules()];
    }
}
