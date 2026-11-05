<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectUnit;
use App\Support\PublicView;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\Response;

/** A developer's project and its unit models as a guest sees them in the app (a ready project's unit needs its licence). */
class ProjectPageController extends Controller
{
    public function show(Project $project): View
    {
        abort_unless(PublicView::project($project, null), 404);
        $project->load(['developer', 'media', 'units.licence']);
        $units = $project->units->filter(fn (ProjectUnit $unit) => $unit->isAdvertisable($project))->values();

        return view('site.projects.show', ['project' => $project, 'units' => $units]);
    }

    public function unit(Project $project, ProjectUnit $unit): View
    {
        $unit->load(['licence', 'media']);
        abort_unless(PublicView::unit($project, $unit, null), 404);
        $project->load('developer');

        return view('site.projects.unit', ['project' => $project, 'unit' => $unit]);
    }

    public function image(Project $project): Response
    {
        abort_unless(PublicView::project($project, null), 404);
        $cover = $project->media()->first();
        abort_if($cover === null, 404);

        return redirect()->away($cover->url('large'))->setPublic()->setMaxAge(300);
    }
}
