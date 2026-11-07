<?php

namespace App\Ai\Assistants;

use App\Ai\Capture\TrainingCapture;
use App\Ai\Masking\AiMasking;
use App\Ai\Retrieval\Need;
use App\Models\Customer;
use App\Models\SearchMiss;

/** A search with no candidate: no AI call, but the earliest signal of unmet demand (search_no_results) — a tester's is no signal. */
final class SearchMisses
{
    public static function record(string $context, Need $need, ?Customer $customer): void
    {
        if ($customer !== null && $customer->is_tester) {
            return;
        }
        SearchMiss::query()->create([
            'customer_id' => $customer?->id,
            'context' => $context,
            'city' => $need->city,
            'neighborhood' => $need->neighborhoods[0] ?? null,
            'property_type' => $need->types[0] ?? null,
            'budget_min' => $need->budgetMin,
            'budget_max' => $need->budgetMax,
            'free_text' => $need->freeText === '' ? null : AiMasking::text($need->freeText, $customer === null ? [] : [$customer->name]),
            'created_at' => now(),
        ]);
        if ($need->freeText !== '') {
            TrainingCapture::searchMiss($need->freeText, $customer);
        }
    }
}
