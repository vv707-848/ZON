<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\Capture\TrainingCapture;
use App\Ai\Masking\AiMasking;
use App\Enums\NoteCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Crm\ClientRequest;
use App\Models\ClientNote;
use App\Models\CrmClient;
use App\Support\Api;
use App\Support\ArabicText;
use App\Support\Ownership;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * «إدارة العملاء» — zero AI except the summary button (POST /ai/summaries). Every query carries broker_id = me:
 * another broker's client answers 404 as if it did not exist.
 */
class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = $request->user('api')->clients()
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('q'), function ($q, $v) {
                $digits = (string) preg_replace('/\D/', '', ArabicText::asciiDigits((string) $v));   // a name has none: then the phone is not searched

                return $q->where(fn ($w) => $w->where('name', 'like', '%'.addcslashes((string) $v, '%_\\').'%')
                    ->when($digits !== '', fn ($w) => $w->orWhere('phone', 'like', '%'.ltrim($digits, '0').'%')));
            })
            ->withCount('notes')->latest('updated_at')->paginate(30);

        return Api::ok($page->getCollection()->map(fn (CrmClient $c) => $this->present($c)), ['page' => ['current' => $page->currentPage(), 'last' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function store(ClientRequest $request): JsonResponse
    {
        return Api::created($this->present($request->customer()->clients()->create($request->validated())));
    }

    public function show(Request $request, CrmClient $client): JsonResponse
    {
        Ownership::assert($client, $request->user('api'));

        return Api::ok($this->present($client->loadCount('notes')));
    }

    public function update(ClientRequest $request, CrmClient $client): JsonResponse
    {
        Ownership::assert($client, $request->customer());
        $client->fill($request->validated())->save();

        return Api::ok($this->present($client));
    }

    public function destroy(Request $request, CrmClient $client): JsonResponse
    {
        Ownership::assert($client, $request->user('api'));
        $client->delete();

        return Api::ok();
    }

    public function notes(Request $request, CrmClient $client): JsonResponse
    {
        Ownership::assert($client, $request->user('api'));

        return Api::ok($client->notes()->limit(200)->get()->map(fn (ClientNote $n) => ['id' => $n->id, 'category' => $n->category->value, 'body' => $n->body, 'at' => $n->created_at?->toIso8601String()]));
    }

    public function addNote(Request $request, CrmClient $client): JsonResponse
    {
        Ownership::assert($client, $request->user('api'));
        $data = $request->validate(['category' => ['required', Rule::enum(NoteCategory::class)], 'body' => ['required', 'string', 'max:2000']]);
        $note = $client->notes()->create($data);
        $client->touch();
        TrainingCapture::note('client_note', AiMasking::text($data['body'], [$client->name]), $data['category'], $request->user('api'));

        return Api::created(['id' => $note->id, 'category' => $note->category->value, 'body' => $note->body, 'at' => $note->created_at?->toIso8601String()]);
    }

    public function deleteNote(Request $request, CrmClient $client, ClientNote $note): JsonResponse
    {
        Ownership::assert($client, $request->user('api'));
        abort_unless($note->client_id === $client->id, 404);
        $note->delete();

        return Api::ok();
    }

    /** @return array<string, mixed> */
    private function present(CrmClient $client): array
    {
        $number = fn ($v) => $v === null ? null : (float) $v;

        return [
            'id' => $client->id,
            'name' => $client->name,
            'phone' => $client->phone === null ? null : Phone::local($client->phone),
            'status' => $client->status->value,
            'purpose' => $client->purpose?->value,
            'city' => $client->city,
            'neighborhoods' => $client->neighborhoods ?? [],
            'property_types' => $client->property_types ?? [],
            'budget_min' => $number($client->budget_min),
            'budget_max' => $number($client->budget_max),
            'area_min' => $number($client->area_min),
            'rooms_min' => $client->rooms_min,
            'description' => $client->description,
            'from_request_id' => $client->source_request_id,
            'notes_count' => $client->notes_count ?? null,
            'updated_at' => $client->updated_at?->toIso8601String(),
        ];
    }
}
