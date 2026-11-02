<?php

namespace App\Ai\Retrieval;

use App\Models\BuyerPreference;

/**
 * What someone looks for (§10 «الاسترجاع»). City, neighbourhood(s) and type(s) are hard filters in SQL; the
 * budget only ORDERS candidates (closest price first) — never excludes; bedrooms and the free text are judged by
 * the model. Types empty = every type (a wish without types).
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
    ) {}

    /** @param array<string, mixed> $input validated input of POST /ai/search, /ai/matching */
    public static function fromInput(array $input): self
    {
        return new self(
            (string) $input['city'],
            array_values((array) ($input['neighborhoods'] ?? [$input['neighborhood']])),
            array_values((array) ($input['property_types'] ?? (isset($input['property_type']) ? [$input['property_type']] : []))),
            $input['purpose'] ?? null,
            isset($input['budget_min']) ? (string) $input['budget_min'] : null,
            isset($input['budget_max']) ? (string) $input['budget_max'] : null,
            isset($input['bedrooms']) ? (int) $input['bedrooms'] : null,
            mb_substr(trim((string) ($input['query'] ?? '')), 0, (int) config('zon.ai.free_text_max')),
        );
    }

    public static function fromPreference(BuyerPreference $wish): self
    {
        return new self(
            $wish->city,
            array_values($wish->neighborhoods),
            array_values($wish->property_types ?? []),
            $wish->purpose->value,
            $wish->budget_min === null ? null : (string) $wish->budget_min,
            $wish->budget_max === null ? null : (string) $wish->budget_max,
            $wish->bedrooms,
            mb_substr(trim((string) $wish->notes), 0, (int) config('zon.ai.free_text_max')),
        );
    }

    /** The price the candidates are ordered by: the top of the budget, else its bottom. */
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
