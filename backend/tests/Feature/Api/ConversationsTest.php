<?php

namespace Tests\Feature\Api;

use App\Models\BuyerPreference;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Message;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * «المحادثات» as the app does it with a server (chats_screen.dart, chat_thread_screen.dart, server_chats.dart):
 * the first message about a listing opens the conversation — once however often sent — then the list with what
 * is unread, the thread, a reply, the read mark, and older messages page by page. Its answers are the app's
 * recorded contract.
 */
class ConversationsTest extends TestCase
{
    private function seeker(): Customer
    {
        $seeker = Customer::factory()->seeker()->create(['name' => 'محمد الشهري']);
        BuyerPreference::factory()->for($seeker)->create();

        return $seeker;
    }

    public function test_the_first_message_opens_it_once_then_the_list_the_thread_a_reply_and_the_read_mark(): void
    {
        $broker = Customer::factory()->broker()->create(['name' => 'مكتب الإتقان']);
        $listing = Listing::factory()->published()->for($broker)->create(['title' => 'شقة في النرجس']);
        $seeker = $this->signIn($this->seeker());

        $first = ['to' => $broker->username, 'listing_id' => $listing->id, 'body' => 'هل العرض متاح؟'];
        $opened = $this->postJson('/api/v1/conversations', $first, ['Idempotency-Key' => 'chat-0001-abcdef'])->assertCreated()
            ->assertJsonPath('data.message.mine', true)->assertJsonPath('data.message.body', 'هل العرض متاح؟');
        $this->postJson('/api/v1/conversations', $first, ['Idempotency-Key' => 'chat-0001-abcdef'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame([1, 1], [Conversation::query()->count(), Message::query()->count()], 'sent twice, said once');
        $this->apiContract('conversation_opened', $opened);
        $id = $opened->json('data.conversation_id');

        // a second first message about the same listing is the same conversation
        $this->postJson('/api/v1/conversations', ['body' => 'وهل السعر قابل للتفاوض؟'] + $first)->assertCreated()->assertJsonPath('data.conversation_id', $id);

        $this->signIn($broker);
        $list = $this->getJson('/api/v1/conversations')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.unread', 2)
            ->assertJsonPath('data.0.with.name', 'محمد الشهري')->assertJsonPath('data.0.subject.title', 'شقة في النرجس')
            ->assertJsonPath('data.0.last_message.mine', false);
        $this->apiContract('conversations', $list);
        $this->apiContract('messages', $this->getJson("/api/v1/conversations/{$id}/messages")->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.body', 'هل العرض متاح؟')->assertJsonPath('data.0.mine', false)->assertJsonPath('meta.has_more', false));
        $reply = $this->postJson("/api/v1/conversations/{$id}/messages", ['body' => 'متاح، تفضل بزيارة.'], ['Idempotency-Key' => 'msg-0001-abcdef'])->assertCreated()
            ->assertJsonPath('data.mine', true);
        $this->postJson("/api/v1/conversations/{$id}/messages", ['body' => 'متاح، تفضل بزيارة.'], ['Idempotency-Key' => 'msg-0001-abcdef'])->assertCreated();
        $this->assertSame(3, Message::query()->count(), 'a reply sent twice is said once');
        $this->apiContract('message_sent', $reply);
        $this->postJson("/api/v1/conversations/{$id}/messages", ['body' => '   '])->assertStatus(422);
        $this->getJson('/api/v1/conversations')->assertJsonPath('data.0.unread', 0)->assertJsonPath('data.0.last_message.mine', true);

        $this->signIn($seeker);
        $this->getJson('/api/v1/conversations')->assertJsonPath('data.0.unread', 1);
        $this->postJson("/api/v1/conversations/{$id}/read")->assertOk();
        $this->getJson('/api/v1/conversations')->assertJsonPath('data.0.unread', 0);

        $this->signIn(Customer::factory()->broker()->create());
        $this->getJson("/api/v1/conversations/{$id}/messages")->assertNotFound();
        $this->postJson("/api/v1/conversations/{$id}/messages", ['body' => 'دخيل'])->assertNotFound();
        $this->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_older_messages_page_by_page(): void
    {
        $broker = Customer::factory()->broker()->create();
        $seeker = $this->signIn($this->seeker());
        $id = $this->postJson('/api/v1/conversations', ['to' => $broker->username, 'body' => 'رسالة 1'])->assertCreated()->json('data.conversation_id');
        for ($i = 2; $i <= 55; $i++) {
            Message::query()->create(['conversation_id' => $id, 'sender_id' => $i % 2 ? $seeker->id : $broker->id, 'body' => "رسالة {$i}"]);
        }

        $newest = $this->getJson("/api/v1/conversations/{$id}/messages")->assertOk()->assertJsonCount(50, 'data')
            ->assertJsonPath('data.49.body', 'رسالة 55')->assertJsonPath('data.0.body', 'رسالة 6')->assertJsonPath('meta.has_more', true);
        $older = $this->getJson("/api/v1/conversations/{$id}/messages?before={$newest->json('data.0.id')}")->assertOk()->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.body', 'رسالة 1')->assertJsonPath('data.4.body', 'رسالة 5')->assertJsonPath('meta.has_more', false);
        $this->apiContract('messages_older', $older);
    }

    public function test_a_message_saved_is_not_failed_by_its_live_or_push_delivery(): void
    {
        $broker = Customer::factory()->broker()->create();
        $this->signIn($this->seeker());
        $real = app(Dispatcher::class);
        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->andReturnUsing(fn () => throw new RuntimeException('queue unavailable'));
        $bus->shouldIgnoreMissing($real);
        $this->app->instance(Dispatcher::class, $bus);
        Bus::clearResolvedInstance(Dispatcher::class);

        $id = $this->postJson('/api/v1/conversations', ['to' => $broker->username, 'body' => 'هل العرض متاح؟'])->assertCreated()->json('data.conversation_id');
        $this->postJson("/api/v1/conversations/{$id}/messages", ['body' => 'وهل يقبل البنك؟'])->assertCreated();
        $this->assertSame(2, Message::query()->count(), 'saved, and said so: the other side reads it on his next look');
    }

    public function test_a_block_stops_new_messages_without_saying_why(): void
    {
        $broker = Customer::factory()->broker()->create();
        $seeker = $this->signIn($this->seeker());
        $id = $this->postJson('/api/v1/conversations', ['to' => $broker->username, 'body' => 'هل العرض متاح؟'])->assertCreated()->json('data.conversation_id');

        $this->signIn($broker);
        $this->postJson("/api/v1/customers/{$seeker->username}/block")->assertOk();
        $this->getJson('/api/v1/conversations')->assertJsonPath('data.0.blocked', true);

        $this->signIn($seeker);
        $this->postJson("/api/v1/conversations/{$id}/messages", ['body' => 'هل وصلتك رسالتي؟'])->assertStatus(403)->assertJsonPath('error.code', 'USER_BLOCKED');
        $this->postJson('/api/v1/conversations', ['to' => $broker->username, 'body' => 'مرحباً'])->assertStatus(403);
        $this->assertSame(1, Message::query()->count());
    }

    public function test_a_reply_in_the_same_second_is_unread_and_reading_stops_at_what_he_was_shown(): void
    {
        $this->travelTo(now()->startOfSecond());   // all in one second
        $broker = Customer::factory()->broker()->create();
        $seeker = $this->signIn($this->seeker());
        $id = $this->postJson('/api/v1/conversations', ['to' => $broker->username, 'body' => 'هل العرض متاح؟'])->assertCreated()->json('data.conversation_id');

        $this->signIn($broker);
        $shown = $this->postJson("/api/v1/conversations/{$id}/messages", ['body' => 'نعم متاح.'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/conversations/{$id}/messages", ['body' => 'ومتى تريد الزيارة؟'])->assertCreated();

        $this->signIn($seeker);
        $this->getJson('/api/v1/conversations')->assertJsonPath('data.0.unread', 2);   // the same second as his own
        $this->postJson("/api/v1/conversations/{$id}/read", ['up_to' => $shown])->assertOk();
        $this->getJson('/api/v1/conversations')->assertJsonPath('data.0.unread', 1);   // the one he was not shown
        $this->postJson("/api/v1/conversations/{$id}/read")->assertOk();
        $this->getJson('/api/v1/conversations')->assertJsonPath('data.0.unread', 0);
        $this->postJson("/api/v1/conversations/{$id}/read", ['up_to' => $shown])->assertOk();
        $this->getJson('/api/v1/conversations')->assertJsonPath('data.0.unread', 0);   // a late mark does not unread
    }
}
