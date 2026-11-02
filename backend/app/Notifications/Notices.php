<?php

namespace App\Notifications;

use App\Enums\BoostKind;
use App\Enums\NoteKind;
use App\Enums\NoticePref;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\OwnerCase;
use App\Models\Package;
use App\Models\Project;
use App\Models\SearchRequest;
use App\Models\Subscription;
use App\Models\Task;

/** Every notice the platform sends, with its text and where a tap leads in the app (router.dart). */
final class Notices
{
    public static function wordingReady(Listing $listing): AppNotice
    {
        return new AppNotice(NoteKind::Listing, 'صياغة عرضك جاهزة', "راجع الوصف المقترح لـ «{$listing->title}» واعتمده أو عدّله.", "/listings/{$listing->id}", NoticePref::ListingStatus, "wording:{$listing->id}");
    }

    public static function wordingReminder(Listing $listing): AppNotice
    {
        return new AppNotice(NoteKind::Listing, 'عرضك بانتظار قرارك', "لم تراجع صياغة «{$listing->title}» بعد. سيُنشر بنصك بعد المهلة.", "/listings/{$listing->id}", NoticePref::ListingStatus, "wording-reminder:{$listing->id}");
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

    public static function boostEnded(Listing $listing, BoostKind $kind): AppNotice
    {
        $title = $kind === BoostKind::Featured ? 'انتهى تمييز عرضك' : 'انتهت مدة «عاجل» لعرضك';

        return new AppNotice(NoteKind::Listing, $title, $listing->title, "/listings/{$listing->id}", NoticePref::ListingStatus, "ended:{$kind->value}:{$listing->id}");
    }

    public static function projectWordingReady(Project $project): AppNotice
    {
        return new AppNotice(NoteKind::Listing, 'صياغة مشروعك جاهزة', "راجع الوصف المقترح لـ «{$project->title}».", "/account/listings/{$project->id}", NoticePref::ListingStatus, "project-wording:{$project->id}");
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

    public static function supportReplied(string $reference): AppNotice
    {
        return new AppNotice(NoteKind::Plan, 'رد على تذكرة الدعم', "التذكرة {$reference}", '/account/settings', null, "ticket:{$reference}");
    }

    public static function announcement(string $title, string $body, ?string $target): AppNotice
    {
        return new AppNotice(NoteKind::Plan, $title, $body, $target ?: '/notifications');
    }
}
