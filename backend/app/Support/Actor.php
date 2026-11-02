<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\User;
use Stringable;

/** Who did something, as stored in history and audit columns: `customer:12` · `staff:3` · `system`. */
final readonly class Actor implements Stringable
{
    private function __construct(public string $kind, public ?int $id) {}

    public static function customer(Customer $customer): self
    {
        return new self('customer', $customer->id);
    }

    public static function staff(User $staff): self
    {
        return new self('staff', $staff->id);
    }

    public static function system(): self
    {
        return new self('system', null);
    }

    public function __toString(): string
    {
        return $this->id === null ? $this->kind : "{$this->kind}:{$this->id}";
    }
}
