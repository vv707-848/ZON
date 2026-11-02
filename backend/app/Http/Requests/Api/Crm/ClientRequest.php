<?php

namespace App\Http\Requests\Api\Crm;

use App\Enums\ClientStatus;
use App\Enums\PropertyType;
use App\Enums\Purpose;
use App\Http\Requests\Api\ApiRequest;
use App\Rules\KnownCity;
use Illuminate\Validation\Rule;

/** A client of the broker's desk (clients_view.dart, client_kit.dart). */
class ClientRequest extends ApiRequest
{
    protected array $phones = ['phone'];

    protected array $numeric = ['budget_min', 'budget_max', 'area_min', 'rooms_min'];

    protected array $ranges = [['budget_min', 'budget_max']];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $need = $this->isMethod('POST') ? 'required' : 'sometimes';
        $client = $this->route('client');

        return [
            'name' => [$need, 'string', 'max:120'],
            'phone' => ['nullable', 'regex:/^\+9665\d{8}$/', Rule::unique('crm_clients')->where('broker_id', $this->customer()->id)->ignore(is_object($client) ? $client->id : null)],
            'status' => ['sometimes', Rule::enum(ClientStatus::class)],
            'purpose' => ['nullable', Rule::enum(Purpose::class)],
            'city' => [$need, 'string', new KnownCity],
            'neighborhoods' => ['nullable', 'array', 'max:10'],
            'neighborhoods.*' => ['string', 'max:80'],
            'property_types' => ['nullable', 'array', 'max:10'],
            'property_types.*' => [Rule::enum(PropertyType::class)],
            'budget_min' => ['nullable', 'numeric', 'gte:0'],
            'budget_max' => ['nullable', 'numeric', 'gt:0'],
            'area_min' => ['nullable', 'numeric', 'gt:0'],
            'rooms_min' => ['nullable', 'integer', 'between:0,50'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['phone.regex' => __('validation.phone'), 'phone.unique' => 'هذا الرقم مسجّل لعميل آخر لديك.'];
    }
}
