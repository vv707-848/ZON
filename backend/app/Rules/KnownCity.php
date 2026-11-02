<?php

namespace App\Rules;

use App\Models\City;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A city of the catalog (cities table, active). */
class KnownCity implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! City::has($value)) {
            $fail(__('validation.city'));
        }
    }
}
