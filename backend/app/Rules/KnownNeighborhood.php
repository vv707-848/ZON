<?php

namespace App\Rules;

use App\Models\City;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A neighbourhood of the given city (or of every value of a list of them). */
class KnownNeighborhood implements ValidationRule
{
    public function __construct(private readonly ?string $city) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->city === null || ! City::has($this->city)) {
            return;   // the city rule reports it
        }
        foreach ((array) $value as $neighborhood) {
            if (! is_string($neighborhood) || ! City::has($this->city, $neighborhood)) {
                $fail(__('validation.neighborhood'));

                return;
            }
        }
    }
}
