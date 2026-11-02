<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Moderation\AutoHideReported;
use App\Enums\ErrorCode;
use App\Enums\ReportReason;
use App\Enums\RequestStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\ContentReport;
use App\Models\ConversationParticipant;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Message;
use App\Models\Project;
use App\Models\Rating;
use App\Models\SearchRequest;
use App\Support\Api;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** «بلاغ»: one per reporter and target; enough different reporters hide the target until staff decide. */
class ReportController extends Controller
{
    public function store(Request $request, AutoHideReported $autoHide): JsonResponse
    {
        $data = $request->validate([
            'target_type' => ['required', Rule::in(ContentReport::TARGETS)],
            'target_id' => ['required', 'integer'],
            'reason' => ['nullable', Rule::enum(ReportReason::class)],
            'details' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $class = Relation::getMorphedModel($data['target_type']);
        $target = $class === null ? null : $class::query()->find($data['target_id']);
        $me = $request->user('api');
        if ($target === null) {
            throw ApiException::of(ErrorCode::NotFound);
        }
        $key = ['reporter_id' => $me->id, 'target_type' => $data['target_type'], 'target_id' => $target->getKey()];
        $already = ContentReport::query()->where($key)->first();
        if ($already !== null) {
            return Api::created(['id' => $already->id]);              // one per reporter, even once the reports hid it
        }
        if (! $this->reportable($target, $me)) {
            throw ApiException::of(ErrorCode::NotFound);
        }

        $report = ContentReport::query()->firstOrCreate($key, ['reason' => $data['reason'] ?? ReportReason::Other, 'details' => $data['details'], 'status' => 'new']);
        if ($report->wasRecentlyCreated) {
            $autoHide($target);
        }

        return Api::created(['id' => $report->id]);
    }

    /**
     * Only what the reporter can see. A message in particular: a reported message is what lets staff read a
     * conversation (Permission::ViewConversationContent), so only its other participant may report it.
     */
    private function reportable(Model $target, Customer $me): bool
    {
        return match (true) {
            $target instanceof Customer => $target->id !== $me->id && $target->anonymized_at === null,
            $target instanceof Listing, $target instanceof Project => $target->status->isPublic(),
            $target instanceof SearchRequest => $target->status === RequestStatus::Published,
            $target instanceof Rating => $target->hidden_at === null,
            $target instanceof Message => $target->sender_id !== $me->id
                && ConversationParticipant::query()->where('conversation_id', $target->conversation_id)->where('customer_id', $me->id)->exists(),
            default => false,
        };
    }
}
