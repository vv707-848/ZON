<?php

namespace Tests\Feature\Admin;

use App\Enums\ListingStatus;
use App\Filament\Resources\ContentReports\Pages\ListContentReports;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Listings\Pages\ViewListing;
use App\Filament\Resources\SupportTickets\Pages\ViewSupportTicket;
use App\Models\ContentReport;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Message;
use App\Models\SupportTicket;
use App\Support\Permissions\Permission;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/** «مشرف»: a listing between «نشط» and «غير نشط» with a reason, messages as «فريق زون», reports and complaints. */
class SupervisionTest extends AdminTestCase
{
    public function test_a_supervisor_makes_a_listing_inactive_and_the_advertiser_reads_why_and_answers(): void
    {
        $listing = Listing::factory()->published()->create();
        $this->signInStaff(...Permission::supervisor());

        Livewire::test(ViewListing::class, ['record' => $listing->getRouteKey()])
            ->callAction('deactivate', ['reason' => 'الصور لا تطابق العقار، حدّثها.'])->assertHasNoActionErrors();

        $this->assertSame('inactive', $listing->fresh()->status->value);
        $ticket = SupportTicket::query()->sole();
        $this->assertSame(['notice', 'staff', 'answered', $listing->id], [$ticket->type->value, $ticket->opened_by, $ticket->status->value, $ticket->about_id]);
        $this->assertSame('الصور لا تطابق العقار، حدّثها.', $ticket->messages()->sole()->body);
        $this->assertSame(1, $listing->customer->notifications()->where('data->key', 'like', "support:{$ticket->reference}:%")->count());
        $this->assertSame(1, Activity::query()->where('event', 'listing_deactivated')->count());

        $this->signIn($listing->customer);
        $this->postJson("/api/v1/support-tickets/{$ticket->reference}/messages", ['body' => 'حدّثت الصور'])->assertOk();
        $this->patchJson("/api/v1/listings/{$listing->id}", ['price' => 870000])->assertOk()->assertJsonPath('data.status', 'published');
    }

    public function test_a_supervisor_brings_a_listing_back_unless_its_licence_ended(): void
    {
        $inactive = Listing::factory()->published()->create();
        $inactive->forceFill(['status' => ListingStatus::Inactive])->save();
        $ended = Listing::factory()->published()->create();
        $ended->forceFill(['status' => ListingStatus::Inactive])->save();
        $ended->licence->forceFill(['expires_at' => now()->subDays(3)->toDateString()])->save();
        $this->signInStaff(...Permission::supervisor());

        Livewire::test(ViewListing::class, ['record' => $inactive->getRouteKey()])->callAction('activate', ['note' => 'عاد بعد المراجعة'])->assertHasNoActionErrors();
        Livewire::test(ViewListing::class, ['record' => $ended->getRouteKey()])->callAction('activate', ['note' => null])->assertHasNoActionErrors();

        $this->assertSame(['published', 'licence_expired'], [$inactive->fresh()->status->value, $ended->fresh()->status->value]);
        $this->assertSame(1, SupportTicket::query()->count(), 'the note reached the advertiser whose listing came back');
    }

    public function test_messages_as_zon_from_an_account_and_a_thread_answered_and_closed(): void
    {
        $customer = Customer::factory()->broker()->create();
        $this->signInStaff(...Permission::supervisor());

        Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
            ->callAction('message', ['subject' => 'رخصة فال', 'body' => 'رخصتك تنتهي الشهر القادم.'])->assertHasNoActionErrors();
        $ticket = SupportTicket::query()->sole();
        $this->assertSame(1, $customer->notifications()->count());

        $this->signIn($customer);
        $this->postJson("/api/v1/support-tickets/{$ticket->reference}/messages", ['body' => 'جددتها اليوم'])->assertOk();
        $this->signInStaff(...Permission::supervisor());
        Livewire::test(ViewSupportTicket::class, ['record' => $ticket->getRouteKey()])
            ->callAction('reply', ['body' => 'وصلنا، شكراً.'])->assertHasNoActionErrors()
            ->callAction('close', ['resolution' => 'جُدّدت الرخصة.'])->assertHasNoActionErrors();

        $this->assertSame(['closed', 'جُدّدت الرخصة.', 3], [$ticket->fresh()->status->value, $ticket->fresh()->resolution, $ticket->messages()->count()]);
        $this->assertNotNull($ticket->fresh()->staff_seen_id);
    }

    public function test_without_the_permissions_the_actions_are_not_there(): void
    {
        $listing = Listing::factory()->published()->create();
        $this->signInStaff(Permission::ViewListings, Permission::ViewCustomers);

        Livewire::test(ViewListing::class, ['record' => $listing->getRouteKey()])->assertActionHidden('deactivate')->assertActionHidden('message_advertiser');
        Livewire::test(ViewCustomer::class, ['record' => $listing->customer->getRouteKey()])->assertActionHidden('message')->assertActionHidden('suspend');
    }

    public function test_a_reported_message_is_shown_alone_to_whoever_decides(): void
    {
        $listing = Listing::factory()->published()->create();
        $buyer = Customer::factory()->create();
        $conversation = Conversation::query()->create(['listing_id' => $listing->id, 'last_message_at' => now()]);
        $conversation->participants()->createMany([['customer_id' => $buyer->id], ['customer_id' => $listing->customer_id]]);
        Message::query()->create(['conversation_id' => $conversation->id, 'sender_id' => $buyer->id, 'body' => 'رسالة سابقة عادية']);
        $reported = Message::query()->create(['conversation_id' => $conversation->id, 'sender_id' => $buyer->id, 'body' => 'رسالة مسيئة']);
        $report = ContentReport::query()->create(['reporter_id' => $listing->customer_id, 'target_type' => 'message', 'target_id' => $reported->id, 'details' => 'إساءة']);
        $this->signInStaff(...Permission::supervisor());

        Livewire::test(ListContentReports::class)->mountTableAction('decide', $report)
            ->assertMountedActionModalSee('رسالة مسيئة')->assertMountedActionModalDontSee('رسالة سابقة عادية');
    }
}
