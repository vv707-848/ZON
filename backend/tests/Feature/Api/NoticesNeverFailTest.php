<?php

namespace Tests\Feature\Api;

use App\Enums\ContractStatus;
use App\Enums\ListingStatus;
use App\Enums\TextReview;
use App\Integrations\Rega\FakeRegaGateway;
use App\Integrations\Rega\RegaGateway;
use App\Models\Customer;
use App\Models\OwnerCase;
use App\Models\Project;
use App\Models\ProjectUnit;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * A notice is told after what it tells is done and saved: a queue that cannot take it fails nothing — a message
 * sent, a project published, an owner's contract answered — nor undoes it.
 */
class NoticesNeverFailTest extends TestCase
{
    private function queueDown(): void
    {
        $real = app(Dispatcher::class);
        $bus = Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->andReturnUsing(fn () => throw new RuntimeException('queue unavailable'));
        $bus->shouldIgnoreMissing($real);
        $this->app->instance(Dispatcher::class, $bus);
        Bus::clearResolvedInstance(Dispatcher::class);
    }

    public function test_a_project_published_and_an_owners_contract_answered_with_the_queue_down(): void
    {
        $developer = $this->signIn(Customer::factory()->developer()->create());
        $project = Project::factory()->create(['developer_id' => $developer->id, 'status' => ListingStatus::Draft, 'text_review' => TextReview::Approved, 'published_at' => null]);
        ProjectUnit::factory()->create(['project_id' => $project->id, 'text_review' => TextReview::Approved]);
        $this->queueDown();
        $this->postJson("/api/v1/projects/{$project->id}/publish")->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertSame(ListingStatus::Published, $project->refresh()->status);

        $this->app->instance(RegaGateway::class, new class extends FakeRegaGateway
        {
            public function contractStatus(string $reference): ContractStatus
            {
                return ContractStatus::Approved;
            }
        });
        $owner = $this->signIn(Customer::factory()->owner()->create());
        $case = OwnerCase::query()->create(['customer_id' => $owner->id, 'deed_number' => '310112233445', 'stage' => 'contract', 'contract_status' => ContractStatus::Submitted, 'contract_ref' => 'SIM-BC-1', 'commission_percent' => '0.50']);
        $this->getJson("/api/v1/owner-cases/{$case->id}")->assertOk()->assertJsonPath('data.stage', 'fee');
        $this->assertSame('fee', $case->refresh()->stage->value);
    }
}
