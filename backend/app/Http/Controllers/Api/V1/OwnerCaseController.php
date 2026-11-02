<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Owner\OwnerCaseFlow;
use App\Enums\OwnerCaseStage;
use App\Enums\PaymentPurpose;
use App\Http\Controllers\Controller;
use App\Models\OwnerCase;
use App\Support\Api;
use App\Support\Ownership;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** «عرض المالك»: the path of each property, with what the owner has to do next. */
class OwnerCaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return Api::ok($request->user('api')->ownerCases()->with('licence')->latest()->get()->map(fn (OwnerCase $c) => $this->present($c)));
    }

    public function store(Request $request, OwnerCaseFlow $flow): JsonResponse
    {
        $data = $request->validate(['deed_number' => ['required', 'regex:/^\d{6,20}$/']]);

        return Api::created($this->present($flow->open($request->user('api'), $data['deed_number'])));
    }

    public function show(Request $request, OwnerCase $ownerCase, OwnerCaseFlow $flow): JsonResponse
    {
        Ownership::assert($ownerCase, $request->user('api'));

        return Api::ok($this->present($flow->sync($ownerCase)->load('licence')));
    }

    /** @return array<string, mixed> */
    private function present(OwnerCase $case): array
    {
        return [
            'id' => $case->id,
            'deed_number' => $case->deed_number,
            'stage' => $case->stage->value,
            'contract_status' => $case->contract_status->value,
            'commission_percent' => (string) $case->commission_percent,
            'licence' => $case->licence === null ? null : ['number' => $case->licence->licence_number, 'qr_url' => $case->licence->qr_url, 'expires_at' => $case->licence->expires_at?->toDateString()],
            'listing_id' => $case->listing_id,
            'next' => match ($case->stage) {
                OwnerCaseStage::Contract => ['action' => 'approve_contract', 'hint' => 'وافق على عقد الوساطة من تطبيق نفاذ'],
                OwnerCaseStage::Fee => ['action' => 'pay_fee', 'purpose' => PaymentPurpose::AdLicence->value, 'price' => Settings::money(Setting::AdLicenceFee)->riyals(), 'target' => ['type' => 'owner_case', 'id' => $case->id]],
                OwnerCaseStage::Licence => ['action' => 'wait_licence'],
                OwnerCaseStage::Listing => ['action' => 'create_listing', 'owner_case_id' => $case->id],
                OwnerCaseStage::Active, OwnerCaseStage::Closed => null,
            },
        ];
    }
}
