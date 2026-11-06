<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** An amount in halalas (integer), never a float. Prices shown to users are gross (VAT included when registered). */
final readonly class Money
{
    public function __construct(public int $halalas) {}

    public static function sar(int|string $riyals): self
    {
        return new self(BigDecimal::of($riyals)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt());
    }

    public function riyals(): string
    {
        return (string) BigDecimal::ofUnscaledValue($this->halalas, 2);
    }

    /**
     * Splits a gross amount into net + VAT at `rate` ("0.15"): what an invoice prints.
     *
     * @return array{net: self, vat: self}
     */
    public function withoutRate(string $rate): array
    {
        $net = BigDecimal::of($this->halalas)->dividedBy(BigDecimal::one()->plus($rate), 0, RoundingMode::HalfUp)->toInt();

        return ['net' => new self($net), 'vat' => new self($this->halalas - $net)];
    }

    /** `percent` of the amount ("0.5" = half a percent), rounded to the halala. */
    public function percent(string $percent): self
    {
        return new self(BigDecimal::of($this->halalas)->multipliedBy($percent)->dividedBy(100, 0, RoundingMode::HalfUp)->toInt());
    }

    public function minus(self $other): self
    {
        return new self($this->halalas - $other->halalas);
    }
}
