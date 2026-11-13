<?php

namespace Tests\Feature\Jobs;

use App\Actions\Projects\SaveUnit;
use App\Enums\ListingStatus;
use App\Enums\TextReview;
use App\Jobs\CleanListingText;
use App\Jobs\CleanProjectText;
use App\Jobs\CleanUnitTexts;
use App\Jobs\Scheduled\RequeueStuckWording;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Project;
use App\Models\ProjectUnit;
use Closure;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * An edit while the AI is answering: the answer was about the old text, so it is not kept beside the new one —
 * the edit's own wording answers it (each edit of a text sends its own).
 */
class WordingRaceTest extends TestCase
{
    /** @var array{0: ?Closure, 1: array<string, mixed>} the edit landing while the AI thinks, and its answer */
    private array $next = [null, []];

    /**
     * The AI answers [output] — after [meanwhile] ran, as an edit landing while it thought.
     *
     * @param  array<string, mixed>  $output
     */
    private function aiAnswersAfter(?Closure $meanwhile, array $output): void
    {
        $this->next = [$meanwhile, $output];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['agents.test/agents/*' => function () {
            [$meanwhile, $output] = $this->next;
            if ($meanwhile !== null) {
                $meanwhile();
            }

            return Http::response(['output' => $output, 'degraded' => false, 'usage' => ['tokens_in' => 300, 'tokens_out' => 80], 'latency_ms' => 900]);
        }]);
    }

    public function test_the_wording_of_a_listings_old_text_is_dropped_after_an_edit(): void
    {
        $listing = Listing::factory()->create(['status' => ListingStatus::Indexing, 'text_review' => TextReview::Pending, 'desc_suggested' => null]);
        $newText = 'وصف جديد مختلف للعقار قرب الحديقة';
        $this->aiAnswersAfter(fn () => Listing::query()->whereKey($listing->id)->update(['desc_raw' => $newText]),
            ['desc_clean' => 'صياغة قديمة لقرب الخدمات والمدارس', 'features' => []]);

        $this->app->call([new CleanListingText($listing->id), 'handle']);

        $listing->refresh();
        $this->assertSame($newText, $listing->desc_raw);
        $this->assertNull($listing->desc_suggested, 'the old text\'s wording is not offered for the new one');
        $this->assertSame(TextReview::Pending, $listing->text_review, 'left for the new text\'s own wording');
        $this->assertSame(ListingStatus::Indexing, $listing->status);

        // and that wording, when it comes, is kept
        $this->aiAnswersAfter(null, ['desc_clean' => 'وصف جديد للعقار قرب الحديقة.', 'features' => []]);
        $this->app->call([new CleanListingText($listing->id), 'handle']);
        $this->assertSame('وصف جديد للعقار قرب الحديقة.', $listing->refresh()->desc_suggested);
        $this->assertSame(TextReview::Ready, $listing->text_review);
    }

    public function test_a_change_of_its_specs_drops_it_too(): void
    {
        $listing = Listing::factory()->create(['status' => ListingStatus::Indexing, 'text_review' => TextReview::Pending, 'desc_suggested' => null]);
        $this->aiAnswersAfter(fn () => Listing::query()->whereKey($listing->id)->update(['neighborhood' => 'الملقا']),
            ['desc_clean' => 'صياغة على الحي القديم', 'features' => []]);

        $this->app->call([new CleanListingText($listing->id), 'handle']);

        $this->assertNull($listing->refresh()->desc_suggested);
        $this->assertSame(TextReview::Pending, $listing->text_review);
    }

    public function test_a_projects_and_a_units_old_text_too(): void
    {
        $developer = Customer::factory()->developer()->create();
        $project = Project::factory()->create(['developer_id' => $developer->id, 'text_review' => TextReview::Pending]);
        $this->aiAnswersAfter(fn () => Project::query()->whereKey($project->id)->update(['desc_raw' => 'وصف المشروع الجديد بعد التعديل']),
            ['desc_clean' => 'صياغة الوصف القديم للمشروع']);
        $this->app->call([new CleanProjectText($project->id), 'handle']);
        $this->assertNull($project->refresh()->desc_suggested);
        $this->assertSame(TextReview::Pending, $project->text_review);

        $edited = ProjectUnit::factory()->create(['project_id' => $project->id, 'text_review' => TextReview::Pending]);
        $kept = ProjectUnit::factory()->create(['project_id' => $project->id, 'text_review' => TextReview::Pending]);
        $this->aiAnswersAfter(fn () => ProjectUnit::query()->whereKey($edited->id)->update(['desc_raw' => 'نموذج معدّل بأربع غرف']),
            ['units' => [['id' => $edited->id, 'desc_clean' => 'صياغة النص القديم'], ['id' => $kept->id, 'desc_clean' => 'نموذج بخمس غرف ومجلس مستقل واسع.']]]);
        $this->app->call([new CleanUnitTexts($project->id), 'handle']);

        $this->assertNull($edited->refresh()->desc_suggested, 'the edited unit waits for its own wording');
        $this->assertSame(TextReview::Pending, $edited->text_review);
        $this->assertSame('نموذج بخمس غرف ومجلس مستقل واسع.', $kept->refresh()->desc_suggested, 'the other is kept');
        $this->assertSame(TextReview::Ready, $kept->text_review);
    }

    public function test_a_unit_edited_while_its_wording_runs_gets_the_wording_of_its_new_text(): void
    {
        $project = Project::factory()->create(['developer_id' => Customer::factory()->developer()->create()->id]);
        $unit = ProjectUnit::factory()->create(['project_id' => $project->id, 'text_review' => TextReview::Pending, 'desc_suggested' => null]);
        $newText = 'وصف جديد للوحدة بأربع غرف';
        $newWording = 'وحدة بأربع غرف ومجلس مستقل.';
        $this->aiAnswersAfter(function () use ($project, $unit, $newText, $newWording) {
            $this->aiAnswersAfter(null, ['units' => [['id' => $unit->id, 'desc_clean' => $newWording]]]);
            app(SaveUnit::class)($project, $unit->fresh(), ['desc_raw' => $newText], null);    // the edit, sent while it ran
        }, ['units' => [['id' => $unit->id, 'desc_clean' => 'صياغة الوصف القديم للوحدة']]]);

        CleanUnitTexts::dispatch($project->id);   // through the queue, its unique lock and all

        $this->assertSame($newText, $unit->refresh()->desc_raw);
        $this->assertSame($newWording, $unit->desc_suggested, 'the edit\'s own wording was sent, and kept');
        $this->assertSame(TextReview::Ready, $unit->text_review);
    }

    public function test_a_project_or_unit_whose_wording_never_left_is_sent_again(): void
    {
        $project = Project::factory()->create(['developer_id' => Customer::factory()->developer()->create()->id, 'text_review' => TextReview::Pending, 'desc_suggested' => null]);
        $unit = ProjectUnit::factory()->create(['project_id' => $project->id, 'text_review' => TextReview::Pending, 'desc_suggested' => null]);
        $this->aiAnswersAfter(null, ['desc_clean' => 'مشروع سكني متكامل قرب الخدمات.', 'units' => [['id' => $unit->id, 'desc_clean' => 'وحدة بثلاث غرف.']]]);

        (new RequeueStuckWording)->handle();
        $this->assertSame(TextReview::Pending, $project->refresh()->text_review, 'not before a quarter of an hour');
        $this->travel(RequeueStuckWording::AFTER_MINUTES + 1)->minutes();
        (new RequeueStuckWording)->handle();

        $this->assertSame('مشروع سكني متكامل قرب الخدمات.', $project->refresh()->desc_suggested);
        $this->assertSame('وحدة بثلاث غرف.', $unit->refresh()->desc_suggested);
    }
}
