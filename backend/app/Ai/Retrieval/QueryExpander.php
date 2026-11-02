<?php

namespace App\Ai\Retrieval;

use App\Models\SearchTerm;
use App\Support\ArabicText;
use Illuminate\Support\Facades\Cache;

/** Local words in the free text → their synonyms («حوش» → «فناء»), ≤ 20, from the panel's dictionary. */
final class QueryExpander
{
    /** @return list<string> */
    public function terms(string $freeText): array
    {
        if (trim($freeText) === '') {
            return [];
        }
        /** @var array<string, list<string>> $dictionary */
        $dictionary = Cache::rememberForever(SearchTerm::CACHE_KEY, fn () => SearchTerm::query()->where('is_active', true)->pluck('synonyms', 'term')->all());

        $terms = [];
        foreach ($dictionary as $term => $synonyms) {
            if (ArabicText::containsAny($freeText, [$term])) {
                array_push($terms, ...$synonyms);
            }
        }

        return array_slice(array_values(array_unique(array_map(fn ($t) => mb_substr((string) $t, 0, 60), $terms))), 0, 20);
    }
}
