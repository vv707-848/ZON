<?php

namespace Tests\Feature\Api;

use App\Enums\AnnouncementKind;
use App\Enums\BoostKind;
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
use App\Notifications\AppNotice;
use App\Notifications\Notices;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Where each notice leads: the bell and the push open its target in the app, so every target is recorded
 * (notice_targets) and the app's tests open each one on the screen it names (test/server/notice_targets_test.dart).
 */
class NoticeTargetsTest extends TestCase
{
    public function test_every_notice_names_a_place_in_the_app(): void
    {
        $listing = (new Listing(['title' => 'شقة في النرجس']))->forceFill(['id' => 12]);
        $project = (new Project(['title' => 'مشروع الندى']))->forceFill(['id' => 3]);
        $unit = (new ProjectUnit(['model_name' => 'A']))->forceFill(['id' => 9, 'project_id' => 3])->setRelation('project', $project);
        $advertiser = (new Customer(['name' => 'مكتب الإتقان']))->forceFill(['id' => 4, 'username' => 'fmson2821']);
        $request = (new SearchRequest(['city' => 'الرياض', 'neighborhoods' => ['النرجس'], 'urgent' => false]))->forceFill(['id' => 7]);
        $package = new Package(['name' => 'وسيط', 'code' => 'broker']);
        $subscription = (new Subscription)->forceFill(['id' => 2])->setRelation('package', $package);
        $task = (new Task(['title' => 'اتصال بالعميل']))->forceFill(['id' => 5]);
        $ticket = new SupportTicket(['reference' => 'ZT-1001']);
        $message = (new SupportMessage(['body' => 'تم']))->forceFill(['id' => 8]);
        $case = (new OwnerCase)->forceFill(['id' => 6, 'stage' => 'contract']);

        $notices = [
            'wording_ready' => Notices::wordingReady($listing),
            'wording_reminder' => Notices::wordingReminder($listing),
            'listing_published' => Notices::listingPublished($listing),
            'listing_inactive' => Notices::listingInactive($listing),
            'listing_hidden' => Notices::listingHidden($listing),
            'licence_expiring' => Notices::licenceExpiring($listing, 7, 1),
            'licence_expired' => Notices::licenceExpired($listing),
            'unit_licence_expiring' => Notices::unitLicenceExpiring($unit, 7, 1),
            'unit_licence_expired' => Notices::unitLicenceExpired($unit),
            'boost_ended' => Notices::boostEnded($listing, BoostKind::Featured),
            'project_wording_ready' => Notices::projectWordingReady($project),
            'project_published' => Notices::projectPublished($project),
            'followed_published' => Notices::followedPublished($advertiser, $listing),
            'rated' => Notices::rated($advertiser, 5),
            'new_message' => Notices::newMessage(1, $advertiser, 'هل العرض متاح؟'),
            'area_request' => Notices::areaRequest($request),
            'matches' => Notices::matches(2),
            'plan_activated' => Notices::planActivated($package),
            'renewal_reminder' => Notices::renewalReminder($subscription),
            'task_reminder' => Notices::taskReminder($task),
            'owner_case' => Notices::ownerCase($case, 'طلبك قيد المراجعة', 'نراجع العقد'),
            'account_activated' => Notices::accountActivated(),
            'support_message' => Notices::supportMessage($ticket, $message),
            'support_closed' => Notices::supportClosed($ticket),
            'announcement' => Notices::announcement('تحديث', 'جديد زون', null, AnnouncementKind::Service),
        ];
        $targets = array_map(fn (AppNotice $n) => $n->target, $notices);

        foreach ($targets as $name => $target) {
            $this->assertMatchesRegularExpression('#^/[^\s]*$#', $target, "{$name} leads to a path of the app");
        }
        $this->apiContract('notice_targets', TestResponse::fromBaseResponse(response()->json(['success' => true, 'data' => $targets])));
        // the shape is not enough here: the app opens these very paths
        if (getenv('ZON_RECORD_API') !== '1') {
            $recorded = json_decode((string) file_get_contents(base_path('../docs/contracts/fixtures/api/notice_targets.json')), true);
            $this->assertSame($recorded['data'], $targets, 'a target changed: record it again and open it in the app (notice_targets_test.dart)');
        }
    }
}
