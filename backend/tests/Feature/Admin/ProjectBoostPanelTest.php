<?php

namespace Tests\Feature\Admin;

use App\Actions\Billing\ApplyBoost;
use App\Enums\BoostKind;
use App\Filament\Resources\BoostUsages\Pages\ListBoostUsages;
use App\Models\Project;
use Livewire\Livewire;

/** The panel shows a project's featuring as a project's, not a request's. */
class ProjectBoostPanelTest extends AdminTestCase
{
    public function test_a_projects_featuring_is_named_a_project(): void
    {
        $project = Project::factory()->published()->create();
        app(ApplyBoost::class)($project->developer, $project, BoostKind::Featured);
        $this->signInSuperadmin();

        Livewire::test(ListBoostUsages::class)->assertSee("مشروع #{$project->id}")->assertDontSee("طلب #{$project->id}");
    }
}
