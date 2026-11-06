<?php

namespace Tests\Feature\Admin;

use App\Actions\Billing\ApplyBoost;
use App\Actions\Moderation\AutoHideReported;
use App\Enums\AccountStatus;
use App\Enums\AnnouncementKind;
use App\Enums\BoostKind;
use App\Enums\ListingStatus;
use App\Enums\ReportStatus;
use App\Enums\RequestStatus;
use App\Filament\Pages\ManageSettings;
use App\Filament\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Resources\ContentReports\Pages\ListContentReports;
use App\Filament\Resources\Conversations\Pages\ListConversations;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Developers\Pages\CreateDeveloper;
use App\Filament\Resources\Developers\Pages\ListDevelopers;
use App\Filament\Resources\IndexEvents\Pages\ListIndexEvents;
use App\Filament\Resources\KbDocuments\Pages\CreateKbDocument;
use App\Filament\Resources\KbDocuments\Pages\EditKbDocument;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Staff\Pages\CreateStaff;
use App\Filament\Widgets\MismatchReasons;
use App\Indexing\IndexSync;
use App\Models\Announcement;
use App\Models\ContentReport;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\IndexEvent;
use App\Models\KbDocument;
use App\Models\Listing;
use App\Models\MarketingContract;
use App\Models\Message;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Rating;
use App\Models\SearchFeedback;
use App\Models\SearchRequest;
use App\Models\User;
use App\Notifications\Notices;
use App\Notifications\PushChannel;
use App\Services\Billing\Entitlements;
use App\Support\Permissions\Permission;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Http\Client\Request as Sent;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/** What staff do from the panel — each through the same rules as the app, each written to the audit log. */
class AdminActionsTest extends AdminTestCase
{
    public function test_suspending_an_account_signs_it_out_and_is_audited(): void
    {
        $this->signInStaff(Permission::ViewCustomers, Permission::SuspendCustomers);
        $customer = Customer::factory()->create();
        $customer->createToken('phone');

        Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])->callAction('suspend', ['reason' => 'احتيال متكرر'])->assertHasNoActionErrors();

        $this->assertSame(['suspended', 0], [$customer->fresh()->status->value, $customer->tokens()->count()]);
        $this->assertSame('احتيال متكرر', Activity::query()->where('event', 'suspended')->sole()->properties['reason']);
    }

    public function test_staff_create_a_developer_who_waits_for_his_contract(): void
    {
        $this->signInStaff(Permission::ViewCustomers, Permission::CreateDevelopers, Permission::ActivateDevelopers);

        Livewire::test(CreateDeveloper::class)->fillForm([
            'name' => 'شركة الأفق', 'username' => 'ofuq', 'phone' => '0551112223', 'city' => 'الرياض', 'password' => 'first-pass-123',
        ])->call('create')->assertHasNoFormErrors();
        $developer = Customer::query()->where('username', 'ofuq')->sole();
        $this->assertSame(['developer', 'pending', '+966551112223'], [$developer->role->value, $developer->status->value, $developer->phone]);

        Livewire::test(ListDevelopers::class)->callTableAction('contract', $developer, ['number' => 'MC-2026-01', 'signed_at' => today()->toDateString(), 'commission_percent' => 2])
            ->assertHasNoTableActionErrors();
        $this->assertSame('active', $developer->fresh()->status->value);
        $this->assertSame('2.00', MarketingContract::query()->sole()->commission_percent);
        $this->assertSame(1, $developer->notifications()->count());
    }

    public function test_unfounded_reports_put_back_what_they_hid(): void
    {
        $listing = Listing::factory()->published()->create();
        foreach (range(1, 3) as $i) {
            $reporter = Customer::factory()->create();
            ContentReport::query()->create(['reporter_id' => $reporter->id, 'target_type' => 'listing', 'target_id' => $listing->id, 'details' => 'مخالف']);
        }
        app(AutoHideReported::class)($listing);
        $this->assertSame('hidden', $listing->fresh()->status->value);
        $this->signInStaff(Permission::HandleReports);

        Livewire::test(ListContentReports::class)->callTableAction('decide', ContentReport::query()->first(), ['decision' => 'reject', 'note' => 'العرض سليم'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('published', $listing->fresh()->status->value);
        $this->assertSame(['rejected'], ContentReport::query()->distinct()->pluck('status')->map->value->all());
    }

    public function test_a_rejected_report_never_brings_back_what_staff_hid_or_a_confirmed_hiding(): void
    {
        $staffHidden = Project::factory()->published()->create(['status' => ListingStatus::Hidden]);          // a person hid it
        $autoHidden = SearchRequest::factory()->create();
        foreach (range(1, 3) as $i) {
            ContentReport::query()->create(['reporter_id' => Customer::factory()->create()->id, 'target_type' => 'request', 'target_id' => $autoHidden->id, 'details' => 'مخالف']);
        }
        app(AutoHideReported::class)($autoHidden);
        $nonsense = ContentReport::query()->create(['reporter_id' => Customer::factory()->create()->id, 'target_type' => 'project', 'target_id' => $staffHidden->id, 'details' => 'بلاغ كيدي']);
        $this->signInStaff(Permission::HandleReports);

        Livewire::test(ListContentReports::class)->callTableAction('decide', $nonsense, ['decision' => 'reject', 'note' => 'بلاغ غير صحيح'])->assertHasNoTableActionErrors();
        $this->assertSame(ListingStatus::Hidden, $staffHidden->fresh()->status, 'only what the reports hid by themselves comes back');

        $first = ContentReport::query()->where('target_type', 'request')->first();
        Livewire::test(ListContentReports::class)->callTableAction('decide', $first, ['decision' => 'accept', 'action' => 'ignored', 'note' => 'مخالف فعلاً'])->assertHasNoTableActionErrors();
        $late = ContentReport::query()->create(['reporter_id' => Customer::factory()->create()->id, 'target_type' => 'request', 'target_id' => $autoHidden->id, 'details' => 'بلاغ آخر']);
        Livewire::test(ListContentReports::class)->callTableAction('decide', $late, ['decision' => 'reject', 'note' => 'لا جديد'])->assertHasNoTableActionErrors();
        $this->assertSame(RequestStatus::Hidden, $autoHidden->fresh()->status, 'an accepted report made the hiding the staff\'s own');
    }

    public function test_an_accepted_report_hides_each_kind_of_content_and_warns_or_suspends_its_owner(): void
    {
        $listing = Listing::factory()->published()->create();
        $request = SearchRequest::factory()->create();
        $rater = Customer::factory()->create();
        $rating = Rating::query()->create(['rater_id' => $rater->id, 'rated_id' => $listing->customer_id, 'stars' => 1, 'comment' => 'تعليق مسيء']);
        $conversation = Conversation::query()->create(['listing_id' => $listing->id, 'last_message_at' => now()]);
        $message = Message::query()->create(['conversation_id' => $conversation->id, 'sender_id' => $rater->id, 'body' => 'رسالة مسيئة']);
        [$project, $warned] = [Project::factory()->published()->create(), Project::factory()->published()->create()];
        $abuser = Customer::factory()->create();
        $abuser->createToken('iPhone 16');
        $this->signInStaff(Permission::HandleReports);
        $decide = function (string $type, int $id, string $action): void {
            $report = ContentReport::query()->create(['reporter_id' => Customer::factory()->create()->id, 'target_type' => $type, 'target_id' => $id, 'details' => 'مخالف']);
            Livewire::test(ListContentReports::class)->callTableAction('decide', $report, ['decision' => 'accept', 'action' => $action, 'note' => 'مخالف للسياسة'])
                ->assertHasNoTableActionErrors();
        };

        foreach ([['listing', $listing->id], ['project', $project->id], ['request', $request->id], ['rating', $rating->id], ['message', $message->id]] as [$type, $id]) {
            $decide($type, $id, 'hidden');
        }
        $this->assertSame([ListingStatus::Hidden, ListingStatus::Hidden, RequestStatus::Hidden], [$listing->fresh()->status, $project->fresh()->status, $request->fresh()->status]);
        $this->assertNotNull($rating->fresh()->hidden_at);
        $this->assertSame('— أُخفيت هذه الرسالة —', $message->fresh()->body);

        $decide('project', $warned->id, 'warned');
        $this->assertSame(ListingStatus::Published, $warned->fresh()->status, 'a warning leaves the content up');
        $this->assertSame('تنبيه من فريق زون', $warned->developer->notifications()->sole()->data['title']);

        $decide('customer', $abuser->id, 'banned');
        $this->assertSame(AccountStatus::Suspended, $abuser->fresh()->status);
        $this->assertSame(0, $abuser->tokens()->count(), 'signed out of every phone');
        $this->assertSame(7, ContentReport::query()->where('status', ReportStatus::Accepted)->count());
    }

    public function test_a_refund_stops_what_the_payment_bought(): void
    {
        $listing = Listing::factory()->published()->create();
        $payment = Payment::query()->create(['customer_id' => $listing->customer_id, 'purpose' => 'featured', 'target_type' => 'listing', 'target_id' => $listing->id,
            'amount_halalas' => 10000, 'provider' => 'fake', 'provider_ref' => 'fake_x', 'status' => 'paid', 'paid_at' => now()]);
        app(ApplyBoost::class)($listing->customer, $listing, BoostKind::Featured, $payment);
        $this->assertTrue($listing->fresh()->isFeatured());
        $this->signInSuperadmin();

        Livewire::test(ListPayments::class)->callTableAction('refund', $payment, ['reason' => 'طلب العميل'])->assertHasNoTableActionErrors();

        $this->assertSame('refunded', $payment->fresh()->status->value);
        $this->assertFalse($listing->fresh()->isFeatured());
    }

    public function test_settings_are_saved_in_riyals_and_audited(): void
    {
        $this->signInSuperadmin();

        Livewire::test(ManageSettings::class)->fillForm(['boosts__featured_price' => '150', 'chat__blocked_terms' => "كلمة\nأخرى"])->call('save')->assertHasNoFormErrors();
        $this->assertSame(15000, Settings::int(Setting::FeaturedPrice));
        $this->assertSame(['كلمة', 'أخرى'], Settings::list(Setting::ChatBlockedTerms));
        $this->assertEquals(['from' => 10000, 'to' => 15000], Activity::query()->where('event', 'settings_updated')->sole()->properties['boosts.featured_price']);

        Livewire::test(ManageSettings::class)->fillForm(['reports__market_min_requests' => 3])->call('save')->assertHasFormErrors(['reports__market_min_requests']);
    }

    public function test_an_announcement_reaches_its_audience_once(): void
    {
        Customer::factory()->broker()->count(2)->create();
        Customer::factory()->seeker()->create();
        $this->signInStaff(Permission::SendAnnouncements);

        Livewire::test(CreateAnnouncement::class)->fillForm(['title' => 'تحديث', 'body' => 'باقات جديدة للوسطاء', 'audience' => 'broker'])->call('create')
            ->assertHasFormErrors(['kind' => 'required']);
        Livewire::test(CreateAnnouncement::class)->fillForm(['kind' => 'service', 'title' => 'اجتماع الوسطاء', 'body' => 'رابط اجتماع الخميس', 'audience' => 'broker'])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame([2, 2], [Announcement::query()->sole()->recipients, Announcement::query()->sole()->pushed], 'a service message: the bell and the phones of all');
    }

    public function test_a_promotion_reaches_every_bell_but_only_the_phones_of_those_who_agreed(): void
    {
        $agreed = Customer::factory()->broker()->create(['notification_prefs' => ['marketing' => true]]);
        $others = Customer::factory()->broker()->count(2)->create();
        $this->signInStaff(Permission::SendAnnouncements);

        Livewire::test(CreateAnnouncement::class)->fillForm(['kind' => 'promotion', 'title' => 'خصم 20%', 'body' => 'على الباقة السنوية هذا الأسبوع', 'audience' => 'broker'])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame([3, 1], [Announcement::query()->sole()->recipients, Announcement::query()->sole()->pushed]);
        foreach ([$agreed, ...$others] as $broker) {
            $this->assertSame(1, $broker->notifications()->count(), 'in every bell');
        }
        $notice = Notices::announcement('خصم 20%', 'نص', null, AnnouncementKind::Promotion);
        $this->assertSame(['database', PushChannel::class], $notice->via($agreed));
        $this->assertSame(['database'], $notice->via($others[0]), 'no consent, no push');
    }

    public function test_a_failed_index_signal_is_sent_again_from_the_panel(): void
    {
        $this->indexAnswer = [['detail' => 'down'], 503];
        app(IndexSync::class)->listing(Listing::factory()->published()->create());
        $event = IndexEvent::query()->sole();
        $this->indexAnswer = [['status' => 'indexed'], 200];
        $this->signInStaff(Permission::ManageIndex);

        Livewire::test(ListIndexEvents::class)->callTableAction('resend', $event)->assertHasNoTableActionErrors();

        $this->assertNotNull($event->fresh()->acked_at);
    }

    public function test_conversation_text_needs_the_sensitive_permission_and_each_look_is_audited(): void
    {
        $listing = Listing::factory()->published()->create();
        $buyer = Customer::factory()->create();
        $conversation = Conversation::query()->create(['listing_id' => $listing->id, 'last_message_at' => now()]);
        $conversation->participants()->createMany([['customer_id' => $buyer->id], ['customer_id' => $listing->customer_id]]);
        $message = Message::query()->create(['conversation_id' => $conversation->id, 'sender_id' => $buyer->id, 'body' => 'رسالة مسيئة']);
        ContentReport::query()->create(['reporter_id' => $listing->customer_id, 'target_type' => 'message', 'target_id' => $message->id, 'details' => 'إساءة']);

        $this->signInStaff(Permission::HandleReports);
        Livewire::test(ListConversations::class)->assertTableActionHidden('content', $conversation);

        $this->signInStaff(Permission::HandleReports, Permission::ViewConversationContent);      // an old role carrying it
        Livewire::test(ListConversations::class)->assertTableActionHidden('content', $conversation);

        $this->signInSuperadmin();
        Livewire::test(ListConversations::class)->mountTableAction('content', $conversation)->assertMountedActionModalSee('رسالة مسيئة');
        $this->assertSame(1, Activity::query()->where('event', 'conversation_viewed')->count());
    }

    public function test_granting_a_plan_from_the_account_page(): void
    {
        $broker = Customer::factory()->broker()->create();
        $this->signInSuperadmin();

        Livewire::test(ViewCustomer::class, ['record' => $broker->getRouteKey()])
            ->callAction('grant', ['package_id' => Package::query()->where('code', 'broker-year')->value('id'), 'days' => 30, 'reason' => 'تعويض'])
            ->assertHasNoActionErrors();

        $this->assertSame('broker-year', Entitlements::for($broker->fresh())->package()->code);
    }

    public function test_a_knowledge_document_saved_in_the_panel_reaches_the_index_and_leaves_it_when_inactive(): void
    {
        $this->signInStaff(Permission::ManageKnowledge);
        Livewire::test(CreateKbDocument::class)->fillForm([
            'title' => 'رسوم ترخيص الإعلان', 'category' => 'licences', 'lang' => 'ar', 'version' => '2', 'effective_date' => today()->toDateString(),
            'source' => 'الهيئة العامة للعقار', 'review_owner' => 'فريق المحتوى', 'next_review' => today()->addMonths(3)->toDateString(),
            'is_active' => true, 'body' => 'يصدر ترخيص الإعلان العقاري من منصة الهيئة قبل نشر العرض.',
        ])->call('create')->assertHasNoFormErrors();

        $document = KbDocument::query()->where('title', 'رسوم ترخيص الإعلان')->sole();
        Http::assertSent(fn (Sent $r) => $r->url() === 'http://agents.test/index/kb_document' && $r['entity_id'] === $document->id
            && $r['action'] === 'create' && $r['payload']['review_owner'] === 'فريق المحتوى' && $r->hasHeader('X-Signature'));

        Livewire::test(EditKbDocument::class, ['record' => $document->getRouteKey()])->fillForm(['is_active' => false])->call('save')->assertHasNoFormErrors();
        Http::assertSent(fn (Sent $r) => $r->url() === 'http://agents.test/index/kb_document' && $r['action'] === 'delete' && ! array_key_exists('payload', $r->data()));   // contract §4: no payload on delete
        $this->assertSame(2, IndexEvent::query()->where('entity', 'kb_document')->where('entity_id', $document->id)->count());
    }

    public function test_the_mismatch_reasons_are_summarized_by_the_ai_without_contacts(): void
    {
        SearchFeedback::query()->create(['customer_id' => Customer::factory()->create()->id, 'context' => 'matching', 'result_type' => 'listing', 'result_id' => 1,
            'is_match' => false, 'reason' => 'بعيد عن المدارس، كلمني 0551234567', 'query_snapshot' => [], 'created_at' => now()]);
        Http::fake(['agents.test/agents/summarize_batch' => Http::response(['output' => ['summary' => 'البعد عن المدارس أبرز الأسباب.', 'themes' => [['theme' => 'المدارس', 'share' => 'high']]],
            'degraded' => false, 'usage' => ['tokens_in' => 300, 'tokens_out' => 40], 'latency_ms' => 900])]);
        $this->signInStaff(Permission::ViewAi);

        Livewire::test(MismatchReasons::class)->callTableAction('themes')->assertNotified('أبرز الأسباب');
        Http::assertSent(fn (Sent $r) => str_ends_with($r->url(), '/agents/summarize_batch') && $r['mode'] === 'map' && ! str_contains($r->body(), '0551234567'));
    }

    public function test_who_may_enter_the_panel_and_with_what_is_always_in_the_audit(): void
    {
        $this->signInSuperadmin();
        $role = Role::query()->where('name', 'مشرف')->sole();
        Livewire::test(CreateStaff::class)->fillForm(['name' => 'نورة', 'email' => 'noura@zon.test', 'password' => 'a-long-secret-123', 'type' => 'admin', 'roles' => [$role->id]])
            ->call('create')->assertHasNoFormErrors();
        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])->fillForm(['permissions' => []])->call('save')->assertHasNoFormErrors();

        $created = Activity::query()->where('event', 'staff_created')->sole();
        $this->assertSame(['noura@zon.test', ['مشرف']], [$created->properties['email'], $created->properties['roles']]);
        $this->assertStringNotContainsString('a-long-secret-123', (string) json_encode(Activity::query()->get()->toArray()));
        $this->assertSame([], Activity::query()->where('event', 'role_updated')->sole()->properties['permissions']);
        $this->assertTrue(User::query()->where('email', 'noura@zon.test')->sole()->hasRole('مشرف'));
    }
}
