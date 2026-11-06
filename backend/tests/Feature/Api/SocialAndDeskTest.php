<?php

namespace Tests\Feature\Api;

use App\Enums\ListingStatus;
use App\Models\BuyerPreference;
use App\Models\ContentReport;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Listing;
use App\Models\Message;
use App\Models\PropertyOwner;
use App\Models\SupportTicket;
use App\Models\TrainingSample;
use App\Notifications\Notices;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Between accounts (profiles, follows, stars, blocks, chats, reports, the bell) and the broker's private desk. */
class SocialAndDeskTest extends TestCase
{
    private function seekerWithWish(): Customer
    {
        $seeker = Customer::factory()->seeker()->create();
        BuyerPreference::factory()->for($seeker)->create();

        return $seeker;
    }

    public function test_profiles_follows_and_stars(): void
    {
        $broker = Customer::factory()->broker()->create();
        $seeker = $this->signIn($this->seekerWithWish());

        $this->postJson("/api/v1/customers/{$broker->username}/follow")->assertOk();
        $this->postJson("/api/v1/customers/{$broker->username}/rating", ['stars' => 4])->assertOk();
        $this->postJson("/api/v1/customers/{$broker->username}/rating", ['stars' => 5, 'comment' => 'تعامل راقٍ'])->assertOk();
        $this->getJson("/api/v1/customers/{$broker->username}")->assertOk()
            ->assertJsonPath('data.rating', ['average' => 5, 'count' => 1])->assertJsonPath('data.is_following', true)->assertJsonPath('data.fal_verified', true);
        $this->assertSame(1, $broker->notifications()->where('type', 'listing')->count(), 'one notice for the first rating only');

        $this->getJson("/api/v1/customers/{$seeker->username}")->assertNotFound();      // a seeker has no public profile
        $this->signIn($broker);
        $this->getJson('/api/v1/me/followers')->assertJsonPath('data.0.username', $seeker->username);
    }

    public function test_a_block_hides_both_ways_and_refuses_messages_without_saying_why(): void
    {
        $broker = Customer::factory()->broker()->create();
        $listing = Listing::factory()->published()->for($broker)->create();
        $seeker = $this->signIn($this->seekerWithWish());

        $conversation = $this->postJson('/api/v1/conversations', ['to' => $broker->username, 'listing_id' => $listing->id, 'body' => 'هل العرض متاح؟'])
            ->assertCreated()->json('data.conversation_id');
        $this->postJson('/api/v1/conversations', ['to' => $broker->username, 'listing_id' => $listing->id, 'body' => 'مرحباً'])
            ->assertCreated()->assertJsonPath('data.conversation_id', $conversation);           // same pair + subject → same conversation
        $this->assertSame(2, $broker->notifications()->where('type', 'message')->count());

        $this->signIn($broker);
        $this->getJson('/api/v1/conversations')->assertJsonPath('data.0.unread', 2)->assertJsonPath('data.0.subject.title', $listing->title);
        $this->postJson("/api/v1/customers/{$seeker->username}/block")->assertOk();

        $this->signIn($seeker);
        $this->postJson("/api/v1/conversations/{$conversation}/messages", ['body' => 'لماذا لا ترد؟'])
            ->assertStatus(403)->assertJsonPath('error.code', 'USER_BLOCKED')->assertJsonPath('error.message', 'تعذّر إرسال الرسالة.');
        $this->getJson("/api/v1/listings/{$listing->id}")->assertNotFound();
        $this->getJson("/api/v1/customers/{$broker->username}")->assertNotFound();
        $this->assertSame(2, Message::query()->count());
    }

    public function test_three_different_reporters_hide_a_listing_until_staff_decide(): void
    {
        $listing = Listing::factory()->published()->create();
        foreach (range(1, 3) as $i) {
            $this->signIn($this->seekerWithWish());
            $this->postJson('/api/v1/reports', ['target_type' => 'listing', 'target_id' => $listing->id, 'reason' => 'sold', 'details' => 'العقار مباع'])->assertCreated();
            $this->postJson('/api/v1/reports', ['target_type' => 'listing', 'target_id' => $listing->id, 'details' => 'مرة ثانية'])->assertCreated();   // one per reporter
        }

        $this->assertSame(3, ContentReport::query()->count());
        $this->assertSame('hidden', $listing->fresh()->status->value);
        $this->assertSame(1, $listing->customer->notifications()->where('data->key', "hidden:{$listing->id}")->count());
    }

    public function test_only_a_participant_reports_a_message_and_conversations_need_a_public_subject(): void
    {
        $listing = Listing::factory()->published()->create();
        $buyer = $this->signIn($this->seekerWithWish());
        $conversation = $this->postJson('/api/v1/conversations', ['to' => $listing->customer->username, 'listing_id' => $listing->id, 'body' => 'متاح؟'])
            ->assertCreated()->json('data.conversation_id');
        $message = Message::query()->where('conversation_id', $conversation)->sole();

        $this->signIn($this->seekerWithWish());                                       // a stranger to that conversation
        $this->postJson('/api/v1/reports', ['target_type' => 'message', 'target_id' => $message->id, 'details' => 'مسيئة'])->assertNotFound();
        $draft = Listing::factory()->create(['status' => ListingStatus::Draft]);
        $this->postJson('/api/v1/conversations', ['to' => $draft->customer->username, 'listing_id' => $draft->id, 'body' => 'مرحبا'])->assertNotFound();
        $this->postJson('/api/v1/conversations', ['to' => $buyer->username, 'listing_id' => $listing->id, 'body' => 'مرحبا'])->assertNotFound();   // not his, not mine

        $this->signIn($listing->customer);                                             // the other participant may
        $this->postJson('/api/v1/reports', ['target_type' => 'message', 'target_id' => $message->id, 'details' => 'مسيئة'])->assertCreated();
        $this->assertSame(1, ContentReport::query()->count());
    }

    public function test_the_conversation_list_is_paged_and_its_queries_do_not_grow_with_it(): void
    {
        $me = $this->signIn($this->seekerWithWish());
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/conversations')->assertOk();

            return count(DB::getQueryLog());
        };
        $open = function (int $n) use ($me) {
            foreach (Listing::factory()->published()->count($n)->create() as $listing) {
                $c = Conversation::query()->create(['listing_id' => $listing->id, 'last_message_at' => now()]);
                $c->participants()->createMany([['customer_id' => $me->id], ['customer_id' => $listing->customer_id]]);
                $c->messages()->create(['sender_id' => $listing->customer_id, 'body' => 'أهلاً']);
            }
        };

        $this->getJson('/api/v1/conversations');                                       // marks «last seen» once a minute: not part of the count
        $open(2);
        $few = $count();
        $open(8);
        $this->assertSame($few, $count(), 'a fixed number of queries per page');
        $this->getJson('/api/v1/conversations')->assertJsonCount(10, 'data')->assertJsonPath('meta.page.total', 10)->assertJsonPath('data.0.unread', 1);
    }

    public function test_a_wish_switched_back_on_counts_against_the_plan(): void
    {
        $seeker = $this->signIn($this->seekerWithWish());                              // the free plan's one active wish
        $first = $seeker->preferences()->sole();
        $this->patchJson("/api/v1/preferences/{$first->id}", ['is_active' => false])->assertOk();
        $second = $this->postJson('/api/v1/preferences', ['city' => 'الرياض', 'neighborhoods' => ['الملقا'], 'property_types' => ['villa']])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/preferences/{$first->id}", ['is_active' => true])->assertStatus(409)->assertJsonPath('error.code', 'PLAN_LIMIT_REACHED');
        $this->assertSame([$second], $seeker->preferences()->where('is_active', true)->pluck('id')->all());
    }

    public function test_the_bell_and_support_tickets(): void
    {
        $broker = $this->signIn(Customer::factory()->broker()->create());
        $broker->notify(Notices::accountActivated());

        $this->getJson('/api/v1/notifications')->assertJsonPath('meta.unread', 1)->assertJsonPath('data.0.target', '/account');
        $this->postJson('/api/v1/notifications/read')->assertJsonPath('data.unread', 0);

        $this->putJson('/api/v1/me/notification-preferences', ['messages' => false])->assertJsonPath('data.messages', false)->assertJsonPath('data.matching', true)
            ->assertJsonPath('data.marketing', false);    // offers' pushes wait for consent
        $this->putJson('/api/v1/me/notification-preferences', ['marketing' => true])->assertJsonPath('data.marketing', true)->assertJsonPath('data.messages', false);

        $reference = $this->postJson('/api/v1/support-tickets', ['body' => 'لا أستطيع رفع الصور'])->assertCreated()->json('data.reference');
        $this->assertMatchesRegularExpression('/^ZT-\d{6}-[A-Z0-9]{5}$/', $reference);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/support-tickets', ['body' => 'استفسار', 'name' => 'زائر', 'phone' => '0559998887'])->assertCreated();
        $this->assertSame([$broker->phone, '+966559998887'], SupportTicket::query()->orderBy('id')->pluck('phone')->all());
    }

    public function test_the_desk_is_private_to_its_broker(): void
    {
        $broker = $this->signIn(Customer::factory()->broker()->create());
        $client = $this->postJson('/api/v1/clients', ['name' => 'سلمان', 'phone' => '0557776665', 'city' => 'الرياض', 'neighborhoods' => ['النرجس']])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/clients', ['name' => 'مكرر', 'phone' => '+966557776665', 'city' => 'الرياض'])->assertStatus(422);
        $this->postJson("/api/v1/clients/{$client}/notes", ['category' => 'interested', 'body' => 'سلمان مهتم بالفلل، جواله 0557776665'])->assertCreated();
        $this->assertSame('العميل مهتم بالفلل، جواله [رقم]', TrainingSample::query()->sole()->text_masked, 'captured without name or number');

        $this->postJson('/api/v1/tasks', ['title' => 'اتصل بسلمان', 'priority' => 'high', 'remind_at' => now()->addHour()->toIso8601String()])->assertCreated();

        $this->postJson('/api/v1/clients', ['name' => 'خالد', 'phone' => '0551112223', 'city' => 'الرياض'])->assertCreated();
        $this->getJson('/api/v1/clients?q=سلمان')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'سلمان');   // a name never matches every phone
        $this->getJson('/api/v1/clients?q=777')->assertJsonCount(1, 'data');

        $this->signIn(Customer::factory()->broker()->create());
        $this->getJson("/api/v1/clients/{$client}")->assertNotFound();
        $this->getJson('/api/v1/clients')->assertJsonCount(0, 'data');

        $this->signIn($this->seekerWithWish());
        $this->getJson('/api/v1/clients')->assertStatus(403)->assertJsonPath('error.code', 'ROLE_FORBIDDEN');
        $this->getJson('/api/v1/tasks')->assertOk();
    }

    public function test_the_owners_book_is_private_to_its_broker(): void
    {
        $broker = $this->signIn(Customer::factory()->broker()->create());
        $listing = Listing::factory()->published()->create(['customer_id' => $broker->id]);
        $owner = $this->postJson('/api/v1/property-owners', ['name' => 'عبدالله الدوسري', 'phone' => '0558889990', 'listing_id' => $listing->id])
            ->assertCreated()->assertJsonPath('data.phone', '0558889990')->json('data.id');
        $this->postJson('/api/v1/property-owners', ['name' => 'مكرر', 'phone' => '+966 55 888 9990'])->assertStatus(422);   // the same phone, written another way
        $this->postJson('/api/v1/property-owners', ['name' => 'مالك', 'phone' => '0551234000', 'listing_id' => Listing::factory()->published()->create()->id])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['listing_id']]]]);            // another advertiser's listing

        $this->postJson("/api/v1/property-owners/{$owner}/notes", ['category' => 'followup', 'body' => 'الدوسري يبي يبيع قبل رمضان، جواله 0558889990'])->assertCreated();
        $this->assertSame('العميل يبي يبيع قبل رمضان، جواله [رقم]', TrainingSample::query()->sole()->text_masked, 'captured without name or number');
        $this->getJson("/api/v1/property-owners/{$owner}")->assertJsonPath('data.notes_count', 1);
        $this->patchJson("/api/v1/property-owners/{$owner}", ['name' => 'عبدالله'])->assertOk()->assertJsonPath('data.phone', '0558889990');

        $this->signIn(Customer::factory()->broker()->create());
        $this->getJson('/api/v1/property-owners')->assertOk()->assertJsonCount(0, 'data');
        foreach (['get' => "/api/v1/property-owners/{$owner}", 'patch' => "/api/v1/property-owners/{$owner}", 'delete' => "/api/v1/property-owners/{$owner}"] as $method => $url) {
            $this->json($method, $url, ['name' => 'غيري'])->assertNotFound();                                                     // as if it did not exist
        }
        $this->getJson("/api/v1/property-owners/{$owner}/notes")->assertNotFound();

        $this->signIn($this->seekerWithWish());
        $this->getJson('/api/v1/property-owners')->assertStatus(403)->assertJsonPath('error.code', 'ROLE_FORBIDDEN');

        $this->signIn($broker);
        $this->deleteJson("/api/v1/property-owners/{$owner}")->assertOk();
        $this->assertSame(0, PropertyOwner::query()->count());
    }

    public function test_stock_is_published_through_the_normal_path_once(): void
    {
        Http::fake(['agents.test/agents/*' => Http::response(['detail' => 'down'], 503)]);
        $broker = $this->signIn(Customer::factory()->broker()->create());
        $item = $this->postJson('/api/v1/inventory', ['purpose' => 'sale', 'property_type' => 'apartment', 'title' => 'شقة من المخزون', 'city' => 'الرياض',
            'neighborhood' => 'الياسمين', 'price' => 750000, 'area_built' => 140, 'bedrooms' => 3, 'description' => 'شقة مخزون جديدة'])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/listings')->assertJsonCount(0, 'data');                    // stock is never public

        $this->postJson("/api/v1/inventory/{$item}/publish")->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['licence_number']]]]);
        $listing = $this->postJson("/api/v1/inventory/{$item}/publish", ['licence_number' => '7200111222'])->assertCreated()->json('data.listing_id');
        $this->postJson("/api/v1/inventory/{$item}/publish", ['licence_number' => '7200111222'])->assertStatus(409);

        $this->assertSame($listing, InventoryItem::query()->sole()->published_listing_id);
        $this->assertSame(['شقة من المخزون', 'published'], [Listing::query()->find($listing)->title, Listing::query()->find($listing)->status->value]);
    }
}
