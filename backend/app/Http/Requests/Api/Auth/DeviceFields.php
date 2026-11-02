<?php

namespace App\Http\Requests\Api\Auth;

/** The optional phone details sent with a sign-in (the push token is registered with the session). */
trait DeviceFields
{
    /** @return array<string, list<string>> */
    protected function deviceRules(): array
    {
        return [
            'device_name' => ['nullable', 'string', 'max:60'],
            'push_token' => ['nullable', 'string', 'max:255'],
            'platform' => ['nullable', 'in:ios,android'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ];
    }

    /** @return array{device_name?: ?string, push_token?: ?string, platform?: ?string, app_version?: ?string} */
    public function device(): array
    {
        return $this->only(['device_name', 'push_token', 'platform', 'app_version']);
    }
}
