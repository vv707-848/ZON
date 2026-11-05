<?php

namespace App\Support;

use App\Enums\CustomerRole;
use App\Enums\RequestStatus;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Project;
use App\Models\ProjectUnit;
use App\Models\SearchRequest;

/**
 * Who may open what — one rule for the app's API and the website, so a page never shows what the API hides.
 * $viewer is null for a guest (every website page) and for a signed-in account its own records are always open.
 *
 * - **Listing:** published with a current advertising licence (Listing::isLive).
 * - **Project:** published; a unit of a ready project only with a valid licence (ProjectUnit::isAdvertisable).
 * - **Profile:** an advertiser (a seeker has no public profile), active, not deleted.
 * - **Request:** published.
 * - Always: nothing of an account that blocked the viewer or that he blocked, and nothing of a review or test
 *   account outside the sandbox (Blocks::hides).
 */
final class PublicView
{
    public static function listing(Listing $listing, ?Customer $viewer): bool
    {
        return self::owns($viewer, $listing->customer_id) || ($listing->isLive() && ! Blocks::hides($viewer, $listing->customer));
    }

    public static function project(Project $project, ?Customer $viewer): bool
    {
        return self::owns($viewer, $project->developer_id) || ($project->status->isPublic() && ! Blocks::hides($viewer, $project->developer));
    }

    public static function unit(Project $project, ProjectUnit $unit, ?Customer $viewer): bool
    {
        return $unit->project_id === $project->id && self::project($project, $viewer)
            && (self::owns($viewer, $project->developer_id) || $unit->isAdvertisable($project));
    }

    public static function profile(Customer $customer, ?Customer $viewer): bool
    {
        return $customer->role !== CustomerRole::Seeker && $customer->anonymized_at === null && $customer->isActive()
            && ($viewer?->id === $customer->id || ! Blocks::hides($viewer, $customer));
    }

    public static function request(SearchRequest $request, ?Customer $viewer): bool
    {
        return self::owns($viewer, $request->customer_id)
            || ($request->status === RequestStatus::Published && ! Blocks::hides($viewer, $request->customer));
    }

    private static function owns(?Customer $viewer, ?int $ownerId): bool
    {
        return $viewer !== null && $viewer->id === $ownerId;
    }
}
