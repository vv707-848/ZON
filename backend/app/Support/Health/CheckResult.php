<?php

namespace App\Support\Health;

use App\Enums\HealthStatus;

/** One line of the health report: what was checked, the outcome, and where to look when it is not ok. */
final readonly class CheckResult
{
    public function __construct(
        public string $key,
        public string $label,
        public HealthStatus $status,
        public string $detail,
        public ?string $hint = null,
    ) {}

    /** @return array{key: string, label: string, status: string, detail: string, hint: ?string} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'status' => $this->status->value,
            'detail' => $this->detail,
            'hint' => $this->hint,
        ];
    }
}
