<?php

namespace App\Ai\Retrieval;

use App\Models\BuyerPreference;

/**
 * What someone looks for (§10 «الاسترجاع»). City, neighbourhood(s) and type(s) are hard filters in SQL; the
 * budget only ORDERS candidates (closest price first) — never excludes; bedrooms and the free text are judged by
 * the model. Types empty = every type (a wish without types).
 *
 * A budget, a room count or an area that only the free text states (StatedNumbers) counts as stated: the search
 * form asks for a description alone. A field that was filled always wins. The area stays here: it narrows the
 * matching's alternatives (ListingCandidates::broaden) and is not part of the agents' contract.
 */
final readonly class Need
{
    /**
     * @param  list<string>  $neighborhoods  at least one
     * @param  list<string>  $types
     */
    public function __construct(
        public string $city,
        public array $neighborhoods,
        public array $types,
        public ?string $purpose = null,
        public ?string $budgetMin = null,
        public ?string $budgetMax = null,
        public ?int $bedrooms = null,
        public string $freeText = '',
        public ?float $area = null,
    ) {}

    /** @param array<string, mixed> $input validated input of POST /ai/search, /ai/matching */
    public static function fromInput(array $input): self
    {
        return self::stating(
            (string) $input['city'],
            array_values((array) ($input['neighborhoods'] ?? [$input['neighborhood']])),
            array_values((array) ($input['property_types'] ?? (isset($input['property_type']) ? [$input['property_type']] : []))),
            $input['purpose'] ?? null,
            isset($input['budget_min']) ? (string) $input['budget_min'] : null,
            isset($input['budget_max']) ? (string) $input['budget_max'] : null,
            isset($input['bedrooms']) ? (int) $input['bedrooms'] : null,
            (string) ($input['query'] ?? ''),
        );
    }

    public static function fromPreference(BuyerPreference $wish): self
    {
        return self::stating(
            $wish->city,
            array_values($wish->neighborhoods),
            array_values($wish->property_types ?? []),
            $wish->purpose->value,
            $wish->budget_min === null ? null : (string) $wish->budget_min,
            $wish->budget_max === null ? null : (string) $wish->budget_max,
            $wish->bedrooms,
            (string) $wish->notes,
        );
    }

    /**
     * @param  list<string>  $neighborhoods
     * @param  list<string>  $types
     */
    private static function stating(string $city, array $neighborhoods, array $types, ?string $purpose, ?string $budgetMin, ?string $budgetMax, ?int $bedrooms, string $text): self
    {
        $text = mb_substr(trim($text), 0, (int) config('zon.ai.free_text_max'));
        $said = StatedNumbers::in($text);
        if ($budgetMin === null && $budgetMax === null && $said['price'] !== null) {
            $budgetMax = (string) $said['price'];
        }

        return new self($city, $neighborhoods, $types, $purpose, $budgetMin, $budgetMax, $bedrooms ?? $said['bedrooms'], $text, $said['area']);
    }

    /** The price the candidates are ordered by: the top of the budget, else its bottom. */
    /** Whether an offer keeps the stated rooms and the budget's top by no more than 10% (agents: ranking.breaks). */
    public function keptBy(?int $bedrooms, ?float $price): bool
    {
        if ($this->bedrooms !== null && ($bedrooms === null || $bedrooms < $this->bedrooms)) {
            return false;
        }

        return $this->budgetMax === null || $price === null || $price <= (float) $this->budgetMax * 1.10;
    }

    /**
     * The fallback with no ranking (the agents down or out of time), as the agents' own (ranking._shortlist): only
     * what keeps the wish (owner's rule, 21 Nov: two that keep it are two results), nearest the top of the budget
     * first, then the lower id — never the order the database happened to return.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Support\Collection<int, T>  $models
     * @param  callable(T): bool  $keeps
     * @param  callable(T): ?float  $price  what is compared with the top of the budget
     * @return \Illuminate\Support\Collection<int, T>
     */
    public function fallback(\Illuminate\Support\Collection $models, callable $keeps, callable $price, ?float $top): \Illuminate\Support\Collection
    {
        $gap = fn ($m): float => ($p = $price($m)) !== null && $top !== null ? abs($p - $top) : INF;

        return $models->filter($keeps)->sortBy([fn ($a, $b) => $gap($a) <=> $gap($b), fn ($a, $b) => $a->getKey() <=> $b->getKey()])->values();
    }

    public function target(): ?string
    {
        return $this->budgetMax ?? $this->budgetMin;
    }

    /** @return array<string, mixed> the `need` of the ranking agents (contract §3.1) — masked by AiGateway */
    public function toAgent(): array
    {
        return [
            'city' => $this->city,
            'neighborhood' => mb_substr(implode('، ', $this->neighborhoods), 0, 80),
            'property_type' => $this->types[0] ?? 'any',
            'purpose' => $this->purpose,
            'budget_min' => $this->budgetMin === null ? null : (float) $this->budgetMin,
            'budget_max' => $this->budgetMax === null ? null : (float) $this->budgetMax,
            'bedrooms' => $this->bedrooms,
            'free_text' => $this->freeText,
        ];
    }
}
