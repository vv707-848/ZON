<?php

namespace App\Http\Resources;

use App\Enums\CustomerRole;
use App\Enums\FalStatus;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The small card of an account shown on its listings, requests and messages.
 *
 * @mixin Customer
 */
class AdvertiserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'name' => $this->name,
            'role' => $this->role->value,
            'fal_verified' => $this->role === CustomerRole::Broker && $this->fal_status === FalStatus::Valid,
        ];
    }
}
