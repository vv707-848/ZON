<?php

namespace App\Http\Requests\Api;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Support\ArabicText;
use App\Support\Phone;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The base of every /api/v1 form. Before the rules run:
 * - $numeric fields typed with Arabic digits or thousands separators become plain ASCII numbers
 *   (anything else is left as typed, so the rule fails — never silently turned into zero);
 * - $phones become E.164 when they are Saudi mobiles (left as typed otherwise, so the rule fails).
 * After the rules: every [min, max] pair of $ranges is compared exactly (BigDecimal).
 * A failure is VALIDATION_FAILED with `details.fields` = field → messages.
 */
abstract class ApiRequest extends FormRequest
{
    /** @var list<string> */
    protected array $numeric = [];

    /** @var list<string> */
    protected array $phones = [];

    /** @var list<array{0: string, 1: string}> */
    protected array $ranges = [];

    public function authorize(): bool
    {
        return true;
    }

    public function customer(): Customer
    {
        $customer = $this->user('api');

        return $customer instanceof Customer ? $customer : throw ApiException::of(ErrorCode::Unauthenticated);
    }

    /** The record the route edits ({listing}, {client}, …), already checked for ownership by the controller. */
    protected function record(): ?Model
    {
        foreach ($this->route()?->parameters() ?? [] as $value) {
            if ($value instanceof Model) {
                return $value;
            }
        }

        return null;
    }

    protected function prepareForValidation(): void
    {
        $changes = [];
        foreach ($this->numeric as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $clean = str_replace([',', '٬', ' ', '٫'], ['', '', '', '.'], ArabicText::asciiDigits(trim($value)));
                $changes[$field] = is_numeric($clean) ? $clean : $value;
            }
        }
        foreach ($this->phones as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $changes[$field] = Phone::normalize($value) ?? $value;
            }
        }
        $this->merge($changes);
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ($this->ranges as [$min, $max]) {
                if ($validator->errors()->hasAny([$min, $max]) || $this->input($min) === null || $this->input($max) === null) {
                    continue;
                }
                try {
                    if (BigDecimal::of((string) $this->input($min))->isGreaterThan((string) $this->input($max))) {
                        $validator->errors()->add($max, __('validation.range_order', ['min' => __("validation.attributes.{$min}")]));
                    }
                } catch (MathException) {
                    $validator->errors()->add($max, __('validation.numeric', ['attribute' => __("validation.attributes.{$max}")]));
                }
            }
        }];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw ApiException::of(ErrorCode::ValidationFailed, ['fields' => $validator->errors()->toArray()]);
    }
}
