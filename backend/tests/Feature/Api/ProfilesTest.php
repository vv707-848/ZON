<?php

namespace Tests\Feature\Api;

use App\Models\BuyerPreference;
use App\Models\Customer;
use App\Models\Follow;
use App\Models\Listing;
use Tests\TestCase;

/**
 * Between accounts as the app will do it with a server: an advertiser's public page and his listings, follow and
 * unfollow, «من أتابع», his stars (one per pair, editable), a block and its undoing, «إعجاب» and its removal, and a
 * conversation read. Its answers are the app's recorded contract.
 */
class ProfilesTest extends TestCase
{
    private function seeker(): Customer
    {
        $seeker = Customer::factory()->seeker()->create();
        BuyerPreference::factory()->for($seeker)->create();

        return $seeker;
    }

    public function test_his_page_his_listings_follow_stars_and_following(): void
    {
        $broker = Customer::factory()->broker()->create(['name' => 'مكتب الإتقان', 'city' => 'الرياض']);
        $live = Listing::factory()->published()->for($broker)->create(['title' => 'شقة منشورة']);
        Listing::factory()->for($broker)->create(['status' => 'paused', 'title' => 'شقة موقوفة']);

        $this->apiContract('profile_public', $this->getJson("/api/v1/customers/{$broker->username}")->assertOk()
            ->assertJsonPath('data.listings_count', 1)->assertJsonPath('data.my_rating', null)->assertJsonPath('data.is_following', false));
        $listings = $this->getJson("/api/v1/customers/{$broker->username}/listings")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $live->id);
        $this->assertStringNotContainsString('شقة موقوفة', $listings->getContent(), 'only what is public');
        $this->apiContract('profile_listings', $listings);

        $seeker = $this->signIn($this->seeker());
        $this->apiContract('follow', $this->postJson("/api/v1/customers/{$broker->username}/follow")->assertOk()->assertJsonPath('data.following', true));
        $this->postJson("/api/v1/customers/{$broker->username}/follow")->assertOk();
        $this->assertSame(1, Follow::query()->count(), 'followed once');
        $this->postJson("/api/v1/customers/{$broker->username}/rating", ['stars' => 0])->assertStatus(422);
        $this->postJson("/api/v1/customers/{$broker->username}/rating", ['stars' => 3])->assertOk();
        $this->apiContract('rating', $this->postJson("/api/v1/customers/{$broker->username}/rating", ['stars' => 5])->assertOk()->assertJsonPath('data.stars', 5));
        $this->apiContract('profile_mine', $this->getJson("/api/v1/customers/{$broker->username}")->assertOk()
            ->assertJsonPath('data.my_rating', 5)->assertJsonPath('data.rating.count', 1)->assertJsonPath('data.is_following', true)
            ->assertJsonPath('data.followers_count', 1));
        $this->apiContract('following', $this->getJson('/api/v1/me/following')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.username', $broker->username));

        $this->apiContract('unfollow', $this->deleteJson("/api/v1/customers/{$broker->username}/follow")->assertOk()->assertJsonPath('data.following', false));
        $this->getJson('/api/v1/me/following')->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/customers/{$seeker->username}/follow")->assertNotFound();   // himself
    }

    public function test_a_block_and_its_undoing(): void
    {
        $broker = Customer::factory()->broker()->create();
        $listing = Listing::factory()->published()->for($broker)->create();
        $seeker = $this->signIn($this->seeker());
        $this->postJson("/api/v1/customers/{$broker->username}/follow")->assertOk();
        $this->signIn($broker);
        $this->postJson("/api/v1/customers/{$seeker->username}/follow")->assertNotFound();    // a seeker is not followed

        $this->signIn($seeker);
        $this->apiContract('block', $this->postJson("/api/v1/customers/{$broker->username}/block")->assertOk()->assertJsonPath('data.blocked', true));
        $this->assertSame(0, Follow::query()->count(), 'the follow goes with it');
        $this->getJson("/api/v1/customers/{$broker->username}")->assertNotFound();
        $this->getJson("/api/v1/listings/{$listing->id}")->assertNotFound();
        $this->postJson("/api/v1/customers/{$broker->username}/rating", ['stars' => 1])->assertNotFound();
        $this->postJson("/api/v1/customers/{$broker->username}/follow")->assertNotFound();
        $this->signIn($broker);
        $this->getJson("/api/v1/listings/{$listing->id}")->assertOk();                         // his own

        $this->signIn($seeker);
        $this->apiContract('unblock', $this->deleteJson("/api/v1/customers/{$broker->username}/block")->assertOk()->assertJsonPath('data.blocked', false));
        $this->getJson("/api/v1/customers/{$broker->username}")->assertOk()->assertJsonPath('data.is_following', false);   // not followed again by itself
        $this->getJson("/api/v1/listings/{$listing->id}")->assertOk();
    }

    public function test_a_favourite_removed_and_a_conversation_read(): void
    {
        $broker = Customer::factory()->broker()->create();
        $listing = Listing::factory()->published()->for($broker)->create();
        $this->signIn($this->seeker());

        $this->postJson('/api/v1/favorites', ['type' => 'listing', 'id' => $listing->id])->assertOk();
        $this->getJson('/api/v1/favorites')->assertJsonCount(1, 'data.listings');
        $this->apiContract('favorite_removed', $this->deleteJson("/api/v1/favorites/listing/{$listing->id}")->assertOk()->assertJsonPath('data.favorite', false));
        $this->getJson('/api/v1/favorites')->assertJsonCount(0, 'data.listings');

        $conversation = $this->postJson('/api/v1/conversations', ['to' => $broker->username, 'listing_id' => $listing->id, 'body' => 'هل العرض متاح؟'])
            ->assertCreated()->json('data.conversation_id');
        $this->postJson("/api/v1/conversations/{$conversation}/messages", ['body' => 'وهل السعر قابل للتفاوض؟'])->assertCreated();

        $this->signIn($broker);
        $this->getJson('/api/v1/conversations')->assertJsonPath('data.0.unread', 2);
        $this->apiContract('conversation_read', $this->postJson("/api/v1/conversations/{$conversation}/read")->assertOk());
        $this->getJson('/api/v1/conversations')->assertJsonPath('data.0.unread', 0);

        $this->signIn(Customer::factory()->broker()->create());
        $this->postJson("/api/v1/conversations/{$conversation}/read")->assertNotFound();      // not his
    }
}
