<?php

namespace Tests\Feature\Api;

use App\Actions\Accounts\PurgeAccount;
use App\Actions\Accounts\RequestDeletion;
use App\Actions\Support\SupportDesk;
use App\Enums\TicketType;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use Tests\TestCase;

/** «الدعم»: a complaint, a suggestion or a question becomes a thread with «فريق زون» — numbered, answered, closed with what was done. */
class SupportThreadsTest extends TestCase
{
    public function test_a_complaint_gets_a_number_the_promised_time_and_a_thread_with_zon(): void
    {
        $listing = Listing::factory()->published()->create();
        $me = $this->signIn(Customer::factory()->seeker()->create());

        $created = $this->postJson('/api/v1/support-tickets', [
            'type' => 'complaint', 'subject' => 'إعلان مضلل', 'listing_id' => $listing->id, 'body' => 'السعر في العرض غير صحيح',
        ])->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonPath('data.response_hours', 48);
        $reference = $created->json('data.reference');

        $this->getJson('/api/v1/support-tickets')->assertOk()
            ->assertJsonPath('data.0.type', 'complaint')->assertJsonPath('data.0.opened_by', 'me')
            ->assertJsonPath('data.0.about.id', $listing->id)->assertJsonPath('data.0.unread', false);

        $ticket = SupportTicket::query()->where('reference', $reference)->sole();
        $staff = User::factory()->create(['name' => 'نورة']);
        $reply = app(SupportDesk::class)->replyAsStaff($ticket, $staff, 'تواصلنا مع المعلن وصحّح السعر.');

        $this->assertSame(1, $me->notifications()->where('data->key', "support:{$reference}:{$reply->id}")->where('data->target', "/support/{$reference}")->count());
        $this->getJson('/api/v1/support-tickets')->assertJsonPath('data.0.unread', true)->assertJsonPath('data.0.last_message.author', 'zon');
        $thread = $this->getJson("/api/v1/support-tickets/{$reference}")->assertOk()
            ->assertJsonPath('data.status', 'answered')
            ->assertJsonPath('data.messages.0.author', 'me')->assertJsonPath('data.messages.1.author', 'zon');
        $this->assertStringNotContainsString('نورة', $thread->getContent(), 'staff are «فريق زون», never a name');
        $this->getJson('/api/v1/support-tickets')->assertJsonPath('data.0.unread', false);

        $this->postJson("/api/v1/support-tickets/{$reference}/messages", ['body' => 'شكراً'])->assertOk()->assertJsonPath('data.status', 'open');
        app(SupportDesk::class)->close($ticket->fresh(), $staff, 'صُحّح السعر في العرض.');
        $this->getJson("/api/v1/support-tickets/{$reference}")->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.resolution', 'صُحّح السعر في العرض.');
        $this->assertNotNull($ticket->fresh()->first_response_at);

        $this->postJson("/api/v1/support-tickets/{$reference}/messages", ['body' => 'عاد السعر الخطأ'])->assertOk()
            ->assertJsonPath('data.status', 'open')->assertJsonPath('data.resolution', null);
    }

    public function test_a_thread_is_its_users_alone(): void
    {
        $owner = Customer::factory()->create();
        $ticket = app(SupportDesk::class)->open($owner, TicketType::Question, 'سؤال عن الباقات');
        $this->signIn(Customer::factory()->create());

        $this->getJson("/api/v1/support-tickets/{$ticket->reference}")->assertNotFound();
        $this->postJson("/api/v1/support-tickets/{$ticket->reference}/messages", ['body' => 'تطفل'])->assertNotFound();
        $this->postJson('/api/v1/support-tickets', ['type' => 'notice', 'body' => 'نوع ليس للمستخدم'])->assertStatus(422);
    }

    public function test_a_deleted_account_leaves_the_record_of_its_threads_not_its_words(): void
    {
        $me = Customer::factory()->create();
        $ticket = app(SupportDesk::class)->open($me, TicketType::Suggestion, 'اقتراح يذكر رقمي 0551234567');
        app(SupportDesk::class)->replyAsStaff($ticket, User::factory()->create(), 'شكراً على الاقتراح.');

        $deletion = app(RequestDeletion::class)($me);
        $this->travel(31)->days();
        app(PurgeAccount::class)($deletion->fresh());

        $this->assertSame(['—', 'شكراً على الاقتراح.'], SupportMessage::query()->orderBy('id')->pluck('body')->all());
        $this->assertSame([null, 'suggestion'], [$ticket->fresh()->phone, $ticket->fresh()->type->value]);
    }
}
