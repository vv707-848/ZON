<?php

namespace Tests\Feature\Api;

use App\Enums\ContractStatus;
use App\Integrations\Rega\LicenceCheck;
use App\Integrations\Rega\RegaGateway;
use App\Jobs\CleanListingText;
use App\Jobs\Scheduled\RequeueStuckWording;
use App\Models\Customer;
use App\Models\Listing;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * «عروضي» as the app does it with a server (listing_form.dart, listing_review_screen.dart, my_listings_view.dart,
 * listing_editor.dart): the licence asked of REGA before the form, the photos then the listing — once however often
 * sent — its wording and his decision, his list, an edit. Its answers are the app's recorded contract.
 */
class MyListingsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function form(array $extra = []): array
    {
        return $extra + [
            'purpose' => 'sale', 'property_type' => 'apartment', 'title' => 'شقة في النرجس', 'city' => 'الرياض', 'neighborhood' => 'النرجس',
            'price' => 880000, 'area_built' => 150, 'bedrooms' => 3, 'bathrooms' => 2, 'halls' => 1, 'floor_level' => 'ground', 'age' => 4,
            'has_deed' => true, 'bank_accepted' => true, 'negotiable' => true, 'description' => 'شقة واسعة قريبة من الخدمات والمدارس',
            'maps_link' => null, 'licence_number' => '7200123456',
        ];
    }

    private function wording(string $suggested): void
    {
        Http::fake(['agents.test/agents/*' => Http::response(['output' => ['desc_clean' => $suggested, 'features' => []], 'degraded' => false,
            'usage' => ['tokens_in' => 300, 'tokens_out' => 80], 'latency_ms' => 900])]);
    }

    private function photo(string $name): int
    {
        return (int) $this->post('/api/v1/media', ['photo' => UploadedFile::fake()->image($name, 640, 480)], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    }

    private function regaSays(LicenceCheck $check): void
    {
        $this->app->instance(RegaGateway::class, new class($check) implements RegaGateway
        {
            public function __construct(private readonly LicenceCheck $check) {}

            public function checkFal(string $falNumber): LicenceCheck
            {
                return $this->check;
            }

            public function verifyAdLicence(string $number): LicenceCheck
            {
                return $this->check;
            }

            public function submitBrokerageContract(string $deedNumber, string $ownerPhone): string
            {
                return 'SIM-BC-A-TEST';
            }

            public function contractStatus(string $reference): ContractStatus
            {
                return ContractStatus::Approved;
            }

            public function issueAdLicence(string $contractReference, string $deedNumber): LicenceCheck
            {
                return $this->check;
            }
        });
    }

    public function test_the_licence_is_asked_of_rega_before_the_form_and_an_expired_one_is_refused_everywhere(): void
    {
        $this->signIn(Customer::factory()->broker()->create());

        $ok = $this->postJson('/api/v1/listings/licence-check', ['licence_number' => '7200123456'])->assertOk()
            ->assertJsonPath('data.licence_number', '7200123456');
        $this->assertNotNull($ok->json('data.expires_at'));
        $this->apiContract('licence_check', $ok);
        $this->postJson('/api/v1/listings/licence-check', ['licence_number' => '7200120000'])->assertStatus(422)
            ->assertJsonPath('error.details.fields.licence_number.0', 'رقم الترخيص الإعلاني غير صالح لدى الهيئة.');
        $this->postJson('/api/v1/listings/licence-check', ['licence_number' => '12'])->assertStatus(422);

        $this->regaSays(new LicenceCheck(true, '7200123456', CarbonImmutable::now('Asia/Riyadh')->subDay()));
        $this->postJson('/api/v1/listings/licence-check', ['licence_number' => '7200123456'])->assertStatus(422)
            ->assertJsonPath('error.details.fields.licence_number.0', 'الرخصة منتهية لدى الهيئة. جدّدها لديها أولاً.');
        $this->postJson('/api/v1/listings', $this->form())->assertStatus(422)
            ->assertJsonPath('error.details.fields.licence_number.0', 'الرخصة منتهية لدى الهيئة. جدّدها لديها أولاً.');
        $this->assertSame(0, Listing::query()->count(), 'a licence past its last day publishes nothing');
    }

    public function test_photos_then_the_listing_once_its_wording_and_his_decision(): void
    {
        $this->wording('شقة واسعة قريبة من الخدمات والمدارس، في دور أرضي.');
        $this->signIn(Customer::factory()->broker()->create());
        $first = $this->photo('a.jpg');
        $second = $this->photo('b.jpg');
        $this->apiContract('media', $this->post('/api/v1/media', ['photo' => UploadedFile::fake()->image('c.jpg', 640, 480)], ['Accept' => 'application/json']));

        $body = $this->form(['media_ids' => [$first, $second], 'cover_id' => $first]);
        $created = $this->postJson('/api/v1/listings', $body, ['Idempotency-Key' => 'lst-0001-abcdef'])->assertCreated();
        $this->postJson('/api/v1/listings', $body, ['Idempotency-Key' => 'lst-0001-abcdef'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, Listing::query()->count(), 'sent twice, published once');
        $this->apiContract('listing_created', $created);
        $id = $created->json('data.id');

        $review = $this->getJson("/api/v1/listings/{$id}/review")->assertOk()
            ->assertJsonPath('data.status', 'pending_review')->assertJsonPath('data.text_review', 'ready')
            ->assertJsonPath('data.raw', 'شقة واسعة قريبة من الخدمات والمدارس');
        $this->apiContract('listing_review', $review);
        $this->apiContract('listings_mine', $this->getJson('/api/v1/listings/mine')->assertOk()->assertJsonPath('data.0.status', 'pending_review'));

        $this->postJson("/api/v1/listings/{$id}/review", ['decision' => 'edit', 'text' => 'شقة واسعة قرب المدارس، دور أرضي.'])->assertOk()
            ->assertJsonPath('data.status', 'published')->assertJsonPath('data.description', 'شقة واسعة قرب المدارس، دور أرضي.')
            ->assertJsonPath('data.photos.0.id', $first);
    }

    public function test_a_queue_that_cannot_take_the_wording_fails_nothing_and_it_is_sent_again(): void
    {
        $this->wording('شقة واسعة قريبة من الخدمات والمدارس، في دور أرضي.');
        $this->signIn(Customer::factory()->broker()->create());
        $real = app(Dispatcher::class);
        $failOnce = true;
        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->andReturnUsing(function (object $job) use ($real, &$failOnce) {
            if ($failOnce && $job instanceof CleanListingText) {
                $failOnce = false;
                throw new RuntimeException('queue unavailable');
            }

            return $real->dispatch($job);
        });
        $bus->shouldIgnoreMissing($real);
        $this->app->instance(Dispatcher::class, $bus);

        $this->postJson('/api/v1/listings', $this->form(), ['Idempotency-Key' => 'lst-0002-abcdef'])->assertCreated()->assertJsonPath('data.status', 'indexing');
        $this->postJson('/api/v1/listings', $this->form(), ['Idempotency-Key' => 'lst-0002-abcdef'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $listing = Listing::query()->sole();
        $this->assertSame('indexing', $listing->status->value, 'saved, its wording not sent');

        $this->app->instance(Dispatcher::class, $real);   // the queue takes jobs again
        Bus::clearResolvedInstance(Dispatcher::class);
        (new RequeueStuckWording)->handle();
        $this->assertSame('indexing', $listing->refresh()->status->value, 'not before a quarter of an hour');
        $this->travel(RequeueStuckWording::AFTER_MINUTES + 1)->minutes();
        (new RequeueStuckWording)->handle();
        $this->assertSame('pending_review', $listing->refresh()->status->value, 'sent again: the wording came');
    }

    public function test_an_edit_of_the_text_goes_back_to_the_wording_and_another_account_cannot_edit(): void
    {
        $this->wording('شقة واسعة قريبة من الخدمات والمدارس، في دور أرضي.');
        $broker = $this->signIn(Customer::factory()->broker()->create());
        $photo = $this->photo('a.jpg');
        $id = $this->postJson('/api/v1/listings', $this->form(['media_ids' => [$photo]]))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/listings/{$id}/review", ['decision' => 'reject'])->assertOk()->assertJsonPath('data.status', 'published');

        $this->wording('شقة عائلية واسعة قرب المدارس.');
        $edit = ['title' => 'شقة عائلية في النرجس', 'price' => 860000, 'description' => 'شقة عائلية واسعة قرب المدارس', 'media_ids' => [$photo], 'cover_id' => $photo];
        $this->patchJson("/api/v1/listings/{$id}", $edit)->assertOk()->assertJsonPath('data.title', 'شقة عائلية في النرجس');
        $this->getJson("/api/v1/listings/{$id}/review")->assertOk()->assertJsonPath('data.text_review', 'ready')
            ->assertJsonPath('data.status', 'published');   // it stays public with its decided text meanwhile

        $this->signIn(Customer::factory()->broker()->create());
        $this->patchJson("/api/v1/listings/{$id}", $edit)->assertNotFound();
        $this->assertSame($broker->id, Listing::query()->findOrFail($id)->customer_id);
    }
}
