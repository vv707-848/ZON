<?php

namespace App\SuperAgent;

use App\Enums\PropertyType;
use App\Models\City;
use InvalidArgumentException;

/**
 * One parameter of a report template, declared to the model (report_select's catalog) and checked here before
 * any SQL runs: a name outside the list or a number outside its range is refused, never adjusted.
 */
final readonly class Param
{
    /**
     * @param  ?list<string>  $enum  the accepted values
     * @param  bool  $listed  shown to the model; a long list (every neighbourhood) is checked here, not sent
     */
    private function __construct(
        public string $type,
        public string $label,
        public mixed $default,
        public bool $required,
        public ?array $enum = null,
        public ?int $min = null,
        public ?int $max = null,
        public bool $listed = true,
        public ?string $hint = null,
    ) {}

    public static function city(bool $required = true): self
    {
        return new self('string', 'المدينة', null, $required, array_keys(City::catalog()));
    }

    /** A neighbourhood of the reference (any city's); the model writes it as the question did. */
    public static function neighborhood(): self
    {
        $names = array_values(array_unique(array_merge(...array_values(array_map('array_values', City::catalog())))));

        return new self('string', 'الحي', null, false, $names, listed: false, hint: 'اسم حي من مرجع زون كما ورد في السؤال، أو لا شيء');
    }

    public static function propertyType(bool $required = false): self
    {
        return new self('string', 'نوع العقار', null, $required, PropertyType::values());
    }

    public static function days(int $default = 30): self
    {
        return new self('integer', 'عدد الأيام', $default, false, null, 1, 365);
    }

    public static function months(int $default = 6): self
    {
        return new self('integer', 'عدد الأشهر', $default, false, null, 1, 24);
    }

    public static function limit(int $default = 10): self
    {
        return new self('integer', 'عدد الصفوف', $default, false, null, 1, 50);
    }

    /** @return array<string, mixed> JSON schema of the parameter (contract §3.5) */
    public function schema(): array
    {
        return array_filter(['type' => $this->type, 'enum' => $this->listed ? $this->enum : null, 'description' => $this->hint,
            'minimum' => $this->min, 'maximum' => $this->max, 'default' => $this->default], fn ($v) => $v !== null);
    }

    public function check(string $name, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            if ($this->required) {
                throw new InvalidArgumentException("{$name}: required");
            }

            return $this->default;
        }
        if ($this->type === 'integer') {
            if (! is_numeric($value) || (int) $value != $value || (int) $value < $this->min || (int) $value > $this->max) {
                throw new InvalidArgumentException("{$name}: outside {$this->min}–{$this->max}");
            }

            return (int) $value;
        }
        if (! is_string($value) || ($this->enum !== null && ! in_array($value, $this->enum, true))) {
            throw new InvalidArgumentException("{$name}: not in the list");
        }

        return $value;
    }
}
