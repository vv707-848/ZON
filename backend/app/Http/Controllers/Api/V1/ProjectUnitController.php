<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Projects\DecideProjectTexts;
use App\Actions\Projects\SaveUnit;
use App\Actions\Projects\SellUnits;
use App\Enums\ErrorCode;
use App\Enums\LicenceSource;
use App\Enums\LicenceStatus;
use App\Enums\ListingStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Projects\UnitRequest;
use App\Http\Resources\UnitResource;
use App\Indexing\IndexSync;
use App\Integrations\Rega\RegaGateway;
use App\Models\AdLicense;
use App\Models\Project;
use App\Models\ProjectUnit;
use App\Support\Api;
use App\Support\Ownership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** A developer's unit models: add · edit · remove · their wording decisions · licence (ready projects) · sales. */
class ProjectUnitController extends Controller
{
    public function store(UnitRequest $request, Project $project, SaveUnit $save): JsonResponse
    {
        Ownership::assert($project, $request->customer());
        $unit = $save($project, null, $request->columns(), array_map('intval', $request->input('media_ids', [])));

        return Api::created(new UnitResource($unit->load(['licence', 'media'])));
    }

    public function update(UnitRequest $request, Project $project, ProjectUnit $unit, SaveUnit $save): JsonResponse
    {
        $this->own($request, $project, $unit);
        $save($project, $unit, $request->columns(), $request->has('media_ids') ? array_map('intval', $request->input('media_ids', [])) : null);

        return Api::ok(new UnitResource($unit->load(['licence', 'media'])));
    }

    public function destroy(Request $request, Project $project, ProjectUnit $unit, IndexSync $index): JsonResponse
    {
        $this->own($request, $project, $unit);
        DB::transaction(function () use ($project, $unit, $index) {
            // a project once published keeps one unit model at least (as publishing asked); held, so two removals
            // at once cannot both take the last two
            $held = Project::query()->lockForUpdate()->findOrFail($project->id);
            if ($held->status !== ListingStatus::Draft && $held->units()->count() <= 1) {
                throw ApiException::of(ErrorCode::ProjectNoUnits, ['reason' => 'last_unit']);
            }
            $unit->forceFill(['available_count' => 0])->save();
            $index->unit($unit->setRelation('project', $project));      // out of the index first
            $unit->delete();
        });

        return Api::ok();
    }

    /** @param Request $request {decisions: [{unit_id, decision, text?}]} */
    public function decide(Request $request, Project $project, DecideProjectTexts $decide): JsonResponse
    {
        Ownership::assert($project, $request->user('api'));
        $data = $request->validate([
            'decisions' => ['required', 'array', 'min:1', 'max:100'],
            'decisions.*.unit_id' => ['required', 'integer'],
            'decisions.*.decision' => ['required', Rule::in(['approve', 'edit', 'reject'])],
            'decisions.*.text' => ['nullable', 'required_if:decisions.*.decision,edit', 'string', 'min:10', 'max:2000'],
        ]);
        $decide->units($project, $data['decisions']);

        return Api::ok(UnitResource::collection($project->units()->with(['licence', 'media', 'project'])->get()));
    }

    /** A ready project's licence per unit model, verified with REGA (L01) — also its renewal: the latest licence counts. */
    public function licence(Request $request, Project $project, ProjectUnit $unit, RegaGateway $rega, IndexSync $index): JsonResponse
    {
        $this->own($request, $project, $unit);
        $number = (string) $request->validate(['licence_number' => ['required', 'regex:/^\d{6,12}$/']])['licence_number'];
        $check = $rega->verifyAdLicence($number);
        if (! $check->valid) {
            throw ApiException::of(ErrorCode::ValidationFailed, ['fields' => ['licence_number' => ['رقم الترخيص الإعلاني غير صالح لدى الهيئة.']]]);
        }
        AdLicense::query()->create([
            'project_unit_id' => $unit->id, 'customer_id' => $project->developer_id, 'licence_number' => $number, 'qr_url' => $check->qrUrl,
            'source' => LicenceSource::Entered, 'status' => LicenceStatus::Valid, 'verified_at' => now(), 'expires_at' => $check->expiry(),
        ]);
        if ($project->status === ListingStatus::Published) {
            $index->unit($unit->setRelation('project', $project)->unsetRelation('licence'));   // a renewal brings it back
        }

        return Api::ok(new UnitResource($unit->setRelation('project', $project)->load(['licence', 'media'])));
    }

    public function sold(Request $request, Project $project, ProjectUnit $unit, SellUnits $sell): JsonResponse
    {
        $this->own($request, $project, $unit);
        $data = $request->validate(['count' => ['nullable', 'integer', 'min:1'], 'sale_price' => ['nullable', 'numeric', 'gt:0']]);
        $unit = $sell($unit, (int) ($data['count'] ?? 1), isset($data['sale_price']) ? (string) $data['sale_price'] : null);

        return Api::ok(new UnitResource($unit->load(['licence', 'media'])));
    }

    private function own(Request $request, Project $project, ProjectUnit $unit): void
    {
        Ownership::assert($project, $request->user('api'));
        if ($unit->project_id !== $project->id) {
            throw ApiException::of(ErrorCode::NotFound);
        }
    }
}
