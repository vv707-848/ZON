<?php

namespace App\Actions\Moderation;

use App\Actions\Admin\ModerateAccount;
use App\Enums\AccountStatus;
use App\Enums\ListingStatus;
use App\Enums\NoteKind;
use App\Enums\ReportAction;
use App\Enums\ReportStatus;
use App\Enums\RequestStatus;
use App\Filament\Support\Audit;
use App\Filament\Support\Staff;
use App\Models\ContentReport;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Message;
use App\Models\Project;
use App\Models\Rating;
use App\Models\SearchRequest;
use App\Notifications\AppNotice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A staff decision on a report, applied to every open report of the same target in one transaction:
 * reject (and put back what the reports hid automatically) · accept with ignore / warn / hide / ban.
 */
final class HandleReport
{
    public function __construct(private readonly ModerateContent $content, private readonly ModerateAccount $accounts) {}

    public function __invoke(ContentReport $report, bool $accept, ReportAction $action, string $note): void
    {
        DB::transaction(function () use ($report, $accept, $action, $note) {
            $target = $report->target;
            $owner = $this->owner($target);

            if (! $accept) {
                $this->undoAutoHide($target, $owner, $note);
            } elseif ($target !== null) {
                $this->confirmAutoHide($target);
                match ($action) {
                    ReportAction::Hidden => $this->hide($target, $note),
                    ReportAction::Banned => $owner !== null ? $this->accounts->suspend($owner, "بلاغ مقبول: {$note}") : null,
                    ReportAction::Warned => $owner?->notify(new AppNotice(NoteKind::Plan, 'تنبيه من فريق زون', $note, '/account')),
                    ReportAction::Ignored => null,
                };
            }

            ContentReport::query()->where('target_type', $report->target_type)->where('target_id', $report->target_id)
                ->whereIn('status', [ReportStatus::New, ReportStatus::Reviewing])
                ->update([
                    'status' => $accept ? ReportStatus::Accepted : ReportStatus::Rejected,
                    'action_taken' => $accept ? $action : ReportAction::Ignored,
                    'staff_note' => $note,
                    'handled_by' => Staff::user()->id,
                    'handled_at' => now(),
                ]);
            Audit::log('report_handled', 'معالجة بلاغ', $report, ['accepted' => $accept, 'action' => $action->value, 'note' => $note]);
        });
    }

    private function hide(Model $target, string $note): void
    {
        match (true) {
            $target instanceof Listing && $target->status->canBecome(ListingStatus::Hidden) => $this->content->listing($target, ListingStatus::Hidden, $note),
            $target instanceof Project && $target->status->canBecome(ListingStatus::Hidden) => $this->content->project($target, ListingStatus::Hidden, $note),
            $target instanceof SearchRequest && $target->status === RequestStatus::Published => $this->content->request($target, RequestStatus::Hidden, $note),
            $target instanceof Rating => $target->forceFill(['hidden_at' => now()])->save(),
            $target instanceof Message => $target->forceFill(['body' => '— أُخفيت هذه الرسالة —'])->save(),
            default => null,
        };
    }

    /**
     * Reports judged unfounded: only what the reports hid by themselves (auto_hidden_at) comes back — never what
     * staff hid, nor the content of an account that is suspended or waiting for its deletion.
     */
    private function undoAutoHide(?Model $target, ?Customer $owner, string $note): void
    {
        $auto = ($target instanceof Listing || $target instanceof Project || $target instanceof SearchRequest) && $target->auto_hidden_at !== null;
        $ownerActive = $owner !== null && $owner->status === AccountStatus::Active && ! $owner->pendingDeletion()->exists();
        if ($auto && ! $ownerActive) {
            return;
        }
        if ($auto && $target instanceof Listing && $target->status === ListingStatus::Hidden) {
            $this->content->listing($target, ListingStatus::Published, $note);
        } elseif ($auto && $target instanceof Project && $target->status === ListingStatus::Hidden) {
            $this->content->project($target, ListingStatus::Published, $note);
        } elseif ($auto && $target instanceof SearchRequest && $target->status === RequestStatus::Hidden) {
            $this->content->request($target, RequestStatus::Published, $note);
        } elseif ($target instanceof Rating && $target->hidden_at !== null) {
            $target->forceFill(['hidden_at' => null])->save();
        }
    }

    /** A report accepted: the automatic hiding becomes the staff's own, so no later rejected report undoes it. */
    private function confirmAutoHide(Model $target): void
    {
        if (($target instanceof Listing || $target instanceof Project || $target instanceof SearchRequest) && $target->auto_hidden_at !== null) {
            $target->forceFill(['auto_hidden_at' => null])->save();
        }
    }

    private function owner(?Model $target): ?Customer
    {
        return match (true) {
            $target instanceof Customer => $target,
            $target instanceof Listing, $target instanceof SearchRequest => $target->customer,
            $target instanceof Project => $target->developer,
            $target instanceof Message => $target->sender,
            $target instanceof Rating => $target->rater,
            default => null,
        };
    }
}
