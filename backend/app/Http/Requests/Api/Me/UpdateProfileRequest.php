<?php

namespace App\Http\Requests\Api\Me;

use App\Http\Requests\Api\ApiRequest;
use App\Rules\KnownCity;

/** «تعديل معلوماتي»: name and city (the phone changes through its own code). */
class UpdateProfileRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => ['sometimes', 'required', 'string', 'max:120'], 'city' => ['sometimes', 'required', 'string', new KnownCity]];
    }
}
