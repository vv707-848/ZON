<?php

namespace App\Notifications;

use App\Enums\AnnouncementKind;
use App\Enums\BoostKind;
use App\Enums\NoteKind;
use App\Enums\NoticePref;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\OwnerCase;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectUnit;
use App\Models\SearchRequest;
use App\Models\Subscription;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\Task;
use Illuminate\Support\Str;

/** Every notice the platform sends, with its text and where a tap leads in the app (router.dart). */
final class Notices
{
    public static function wordingReady(Listing $listing): AppNotice
    {
        return new AppNotice(NoteKind::Listing, 'صياغة عرضك جاهزة', "راجع الوصف المقترح لـ «{$listing->title}» واعتمده أو عدّله.", "/account/listings/{$listing->id}/review", NoticePref::ListingStatus, "wording:{$listing->id}");
    }

    public static function wordingReminder(Listing $listing): AppNotice
    {
        return new AppNotice(NoteKind::Listing, 'عرضك بانتظار قرارك', "لم تراجع صياغة «{$listing->title}» بعد. سيُنشر بنصك بعد المهلة.", "/account/listings/{$listing->id}/review", NoticePref::ListingStatus, "wording-reminder:{$listing->id}");
    }

    public static function listingPublished(Listing $listing): AppNotice
    {
        return new AppNotice(NoteKind::Listing, 'نُشر عرضك', $listing->title, "/listings/{$listing->id}", NoticePref::ListingStatus, "published:{$listing->id}");
    }

    public static function listingInactive(Listing $listing): AppNotice
    {
        return new AppNotice(NoteKind::Listing, 'عرضك صار غير نشط', "حدّث «{$listing->title}» ليعود إلى النتائج.", "/account/listings/{$listing->id}", NoticePref::ListingStatus, "inactive:{$listing->id}");
    }

    public static function listingHidden(Listing $listing): AppNotice
    {
        return new AppNotice(NoteKind::Listing, 'أُخفي عرضك للمراجعة', $listing->title, "/account/listings/{$listing->id}", null, "hidden:{$listing->id}");
    }

    /** A week and a day before the licence's last day — whatever the switches say: the listing is about to leave. */
    public static function licenceExpiring(Listing $listing, int $daysLeft, int $stage): AppNotice
    {
        return new AppNotice(NoteKind::Listing, 'رخصة إعلانك قاربت على الانتهاء',
            "رخصة «{$listing->title}» ".self::endsIn($daysLeft).'. جدّدها لدى الهيئة ثم حدّث رقمها في العرض.',
            "/account/listings/{$listing->id}", null, "licence-expiring:{$listing->id}:{$stage}");
    }

    public static function licenceExpired(Listing $listing): AppNotice
    {
        return new AppNotice(NoteKind::Listing, 'انتهت رخصة إعلانك',
            "«{$listing->title}» خرج من النتائج. جدّد الرخصة لدى الهيئة ثم حدّث رقمها في العرض ليعود.",
            "/account/listings/{$listing->id}", null, "licence-expired:{$listing->id}");
    }

    public static function unitLicenceExpiring(ProjectUnit $unit, int $daysLeft, int $stage): AppNotice
    {
        return new AppNotice(NoteKind::Listing, 'رخصة وحدة في مشروعك قاربت على الانتهاء',
            "رخصة النموذج «{$unit->model_name}» في «{$unit->project->title}» ".self::endsIn($daysLeft).'. جدّدها لدى الهيئة ثم حدّث رقمها في الوحدة.',
            "/account/listings/P{$unit->project_id}", null, "unit-licence-expiring:{$unit->id}:{$stage}");
    }

    public static function unitLicenceExpired(ProjectUnit $unit): AppNotice
    {
        return new AppNotice(NoteKind::Listing, 'انتهت رخصة وحدة في مشروعك',
            "النموذج «{$unit->model_name}» في «{$unit->project->title}» خرج من النتائج. جدّد رخصته لدى الهيئة ثم حدّث رقمها في الوحدة.",
            "/account/listings/P{$unit->project_id}", null, "unit-licence-expired:{$unit->id}");
    }

    public static function boostEnded(Listing $listing, BoostKind $kind): AppNotice
    {
        $title = $kind === BoostKind::Featured ? 'انتهى تمييز عرضك' : 'انتهت مدة «عاجل» لعرضك';

        return new AppNotice(NoteKind::Listing, $title, $listing->title, "/listings/{$listing->id}", NoticePref::ListingStatus, "ended:{$kind->value}:{$listing->id}");
    }

    public static function projectBoostEnded(Project $project): AppNotice
    {
        return new AppNotice(NoteKind::Listing, 'انتهى تمييز مشروعك', $project->title, "/projects/{$project->id}", NoticePref::ListingStatus, "project-ended:featured:{$project->id}");
    }

    public static function projectWordingReady(Project $project): AppNotice
    {
        return new AppNotice(NoteKind::Listing, 'صياغة مشروعك جاهزة', "راجع الوصف المقترح لـ «{$project->title}».", "/account/projects/{$project->id}/review", NoticePref::ListingStatus, "project-wording:{$project->id}");
    }

    public static function projectPublished(Project $project): AppNotice
    {
        return new AppNotice(NoteKind::Listing, 'نُشر مشروعك', $project->title, "/projects/{$project->id}", NoticePref::ListingStatus, "project:{$project->id}");
    }

    public static function followedPublished(Customer $advertiser, Listing $listing): AppNotice
    {
        return new AppNotice(NoteKind::Listing, "عرض جديد من {$advertiser->name}", $listing->title, "/listings/{$listing->id}", NoticePref::FollowedPosts, "followed:{$listing->id}");
    }

    public static function rated(Customer $rated, int $stars): AppNotice
    {
        return new AppNotice(NoteKind::Listing, 'تقييم جديد لملفك', str_repeat('★', $stars), "/users/{$rated->username}", NoticePref::Ratings);
    }

    public static function newMessage(int $conversationId, Customer $sender, string $body): AppNotice
    {
        return new AppNotice(NoteKind::Message, $sender->name, mb_strimwidth($body, 0, 90, '…'), "/chats/{$conversationId}", NoticePref::Messages);
    }

    public static function areaRequest(SearchRequest $request): AppNotice
    {
        $title = $request->urgent ? 'طلب عاجل في منطقتك' : 'طلب جديد في منطقتك';

        return new AppNotice(NoteKind::Request, $title, $request->city.' · '.implode('، ', $request->neighborhoods), "/requests/{$request->id}", NoticePref::AreaRequests, "request:{$request->id}");
    }

    public static function matches(int $count): AppNotice
    {
        return new AppNotice(NoteKind::Match, 'عروض تطابق رغبتك', "{$count} عرض جديد يطابق ما تبحث عنه.", '/account/matching', NoticePref::Matching, 'matches:'.now()->format('Y-m-d-H'));
    }

    public static function planActivated(Package $package): AppNotice
    {
        return new AppNotice(NoteKind::Plan, 'تم تفعيل الباقة', $package->name, '/account/plans', null, "plan:{$package->code}");
    }

    public static function renewalReminder(Subscription $subscription): AppNotice
    {
        return new AppNotice(NoteKind::Plan, 'اشتراكك ينتهي قريباً', "ينتهي {$subscription->package->name} في ".$subscription->ends_at?->format('Y-m-d'), '/account/plans', null, "renewal:{$subscription->id}");
    }

    public static function taskReminder(Task $task): AppNotice
    {
        return new AppNotice(NoteKind::Plan, 'تذكير بمهمة', $task->title, '/account/tasks', null, "task:{$task->id}");
    }

    public static function ownerCase(OwnerCase $case, string $title, string $body): AppNotice
    {
        return new AppNotice(NoteKind::Listing, $title, $body, '/add', NoticePref::ListingStatus, "owner-case:{$case->id}:{$case->stage->value}");
    }

    public static function accountActivated(): AppNotice
    {
        return new AppNotice(NoteKind::Plan, 'تم تفعيل حسابك', 'تستطيع الآن نشر مشاريعك على زون.', '/account');
    }

    /** Staff wrote in a support thread — a reply, or the first message of «فريق زون». Always sent. */
    public static function supportMessage(SupportTicket $ticket, SupportMessage $message): AppNotice
    {
        return new AppNotice(NoteKind::Message, 'رسالة من فريق زون', Str::limit($message->body, 120),
            "/support/{$ticket->reference}", null, "support:{$ticket->reference}:{$message->id}");
    }

    public static function supportClosed(SupportTicket $ticket): AppNotice
    {
        return new AppNotice(NoteKind::Message, "أُغلقت التذكرة {$ticket->reference}", Str::limit((string) $ticket->resolution, 120),
            "/support/{$ticket->reference}", null, "support-closed:{$ticket->reference}");
    }

    public static function announcement(string $title, string $body, ?string $target, AnnouncementKind $kind): AppNotice
    {
        return new AppNotice(NoteKind::Plan, $title, $body, $target ?: '/notifications', promotion: $kind === AnnouncementKind::Promotion);
    }

    private static function endsIn(int $daysLeft): string
    {
        return match (true) {
            $daysLeft <= 0 => 'تنتهي اليوم',
            $daysLeft === 1 => 'تنتهي غداً',
            $daysLeft === 2 => 'تنتهي بعد يومين',
            default => "تنتهي خلال {$daysLeft} أيام",
        };
    }
}
