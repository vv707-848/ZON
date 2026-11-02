<?php

namespace App\Indexing;

use App\Ai\Masking\AiMasking;
use App\Models\KbDocument;
use App\Models\Listing;
use App\Models\ProjectUnit;
use App\Models\SearchRequest;

/**
 * What each entity sends to the search index (docs/contracts/agents-api.md §4) — whitelists, nothing else.
 * Only decided, public text is embedded: desc_clean, never desc_raw of an undecided wording.
 * Text a customer wrote passes AiMasking here: the embedding runs on the AI node, so contacts must be gone
 * before the signal leaves (architecture §10.1: masking is step 6, embedding step 7). A listing published as
 * written (the AI was down) or edited by its advertiser can still carry a phone number.
 * Knowledge documents are the platform's own official text and go as written.
 */
final class IndexPayloads
{
    /** @return array<string, mixed> */
    public static function listing(Listing $listing): array
    {
        return [
            'city' => $listing->city,
            'neighborhood' => $listing->neighborhood,
            'property_type' => $listing->property_type->value,
            'purpose' => $listing->purpose->value,
            'price' => (float) $listing->price,
            'area_land' => self::number($listing->area_land),
            'area_built' => self::number($listing->area_built),
            'bedrooms' => $listing->bedrooms,
            'bathrooms' => $listing->bathrooms,
            'age' => $listing->age,
            'deed' => $listing->has_deed,
            'bank' => $listing->bank_accepted,
            'desc_clean' => AiMasking::text($listing->publicDescription()),
            'status' => $listing->status->value,
        ];
    }

    /** @return array<string, mixed> */
    public static function unit(ProjectUnit $unit): array
    {
        $project = $unit->project;

        return [
            'project_id' => $project->id,
            'city' => $project->city,
            'neighborhood' => $project->neighborhood,
            'property_type' => $unit->property_type->value,
            'model' => $unit->model_name,
            'area' => self::number($unit->area ?? $unit->area_built ?? $unit->area_land),
            'bedrooms' => $unit->bedrooms,
            'bathrooms' => $unit->bathrooms,
            'halls' => $unit->halls,
            'price' => (float) $unit->price,
            'desc_clean' => AiMasking::text($unit->publicDescription()),
            'project_context' => AiMasking::text($project->publicDescription()),
        ];
    }

    /** @return array<string, mixed> */
    public static function request(SearchRequest $request): array
    {
        return [
            'city' => $request->city,
            'neighborhoods' => array_values($request->neighborhoods),
            'property_types' => array_values($request->property_types),
            'purpose' => $request->purpose->value,
            'budget_min' => self::number($request->budget_min),
            'budget_max' => self::number($request->budget_max),
            'area_min' => self::number($request->area_min),
            'rooms_min' => $request->rooms_min,
            'urgent' => $request->urgent,
            'desc' => AiMasking::text((string) $request->description),
        ];
    }

    /** @return array<string, mixed> */
    public static function kbDocument(KbDocument $document): array
    {
        return [
            'title' => $document->title,
            'category' => $document->category,
            'lang' => $document->lang,
            'version' => $document->version,
            'effective_date' => $document->effective_date->toDateString(),
            'source' => $document->source,
            'review_owner' => $document->review_owner,
            'next_review' => $document->next_review->toDateString(),
            'body' => $document->body,
        ];
    }

    private static function number(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
