<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\Capture\TrainingCapture;
use App\Ai\Masking\AiMasking;
use App\Enums\NoteCategory;
use App\Http\Controllers\Controller;
use App\Models\OwnerNote;
use App\Models\PropertyOwner;
use App\Support\Api;
use App\Support\Ownership;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** «إدارة الملاك»: the owners a broker deals with, private to him (owners_view.dart). */
class PropertyOwnerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return Api::ok($request->user('api')->propertyOwners()->withCount('notes')->latest('updated_at')->limit(500)->get()->map(fn (PropertyOwner $o) => $this->present($o)));
    }

    public function store(Request $request): JsonResponse
    {
        $owner = $request->user('api')->propertyOwners()->create($this->validated($request, null));

        return Api::created($this->present($owner));
    }

    public function show(Request $request, PropertyOwner $owner): JsonResponse
    {
        Ownership::assert($owner, $request->user('api'));

        return Api::ok($this->present($owner->loadCount('notes')));
    }

    public function update(Request $request, PropertyOwner $owner): JsonResponse
    {
        Ownership::assert($owner, $request->user('api'));
        $owner->fill($this->validated($request, $owner))->save();

        return Api::ok($this->present($owner));
    }

    public function destroy(Request $request, PropertyOwner $owner): JsonResponse
    {
        Ownership::assert($owner, $request->user('api'));
        $owner->delete();

        return Api::ok();
    }

    public function notes(Request $request, PropertyOwner $owner): JsonResponse
    {
        Ownership::assert($owner, $request->user('api'));

        return Api::ok($owner->notes()->limit(200)->get()->map(fn (OwnerNote $n) => ['id' => $n->id, 'category' => $n->category->value, 'body' => $n->body, 'at' => $n->created_at?->toIso8601String()]));
    }

    public function addNote(Request $request, PropertyOwner $owner): JsonResponse
    {
        Ownership::assert($owner, $request->user('api'));
        $data = $request->validate(['category' => ['required', Rule::enum(NoteCategory::class)], 'body' => ['required', 'string', 'max:2000']]);
        $note = $owner->notes()->create($data);
        $owner->touch();
        TrainingCapture::note('owner_note', AiMasking::text($data['body'], [$owner->name]), $data['category'], $request->user('api'));

        return Api::created(['id' => $note->id, 'category' => $note->category->value, 'body' => $note->body, 'at' => $note->created_at?->toIso8601String()]);
    }

    public function deleteNote(Request $request, PropertyOwner $owner, OwnerNote $note): JsonResponse
    {
        Ownership::assert($owner, $request->user('api'));
        abort_unless($note->owner_id === $owner->id, 404);
        $note->delete();

        return Api::ok();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?PropertyOwner $owner): array
    {
        $me = $request->user('api');
        if (is_string($request->input('phone'))) {                     // an edit without a phone keeps the one saved
            $request->merge(['phone' => Phone::normalize($request->input('phone')) ?? $request->input('phone')]);
        }
        $need = $owner === null ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$need, 'string', 'max:120'],
            'phone' => [$need, 'regex:/^\+9665\d{8}$/', Rule::unique('property_owners')->where('broker_id', $me->id)->ignore($owner?->id)],
            'listing_id' => ['nullable', 'integer', Rule::exists('listings', 'id')->where('customer_id', $me->id)],
            'listing_title' => ['nullable', 'string', 'max:160'],
        ], ['phone.regex' => __('validation.phone')]);
    }

    /** @return array<string, mixed> */
    private function present(PropertyOwner $owner): array
    {
        return ['id' => $owner->id, 'name' => $owner->name, 'phone' => Phone::local($owner->phone), 'listing_id' => $owner->listing_id,
            'listing_title' => $owner->listing_title, 'notes_count' => $owner->notes_count ?? null, 'updated_at' => $owner->updated_at?->toIso8601String()];
    }
}
