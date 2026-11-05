<?php

namespace App\Support\Photos;

/** A photo after Photos::clean(): JPEG bytes per size (Photos::SIZES), and the main size's dimensions. */
final readonly class CleanPhoto
{
    /** @param array<string, string> $sizes size => JPEG bytes ('main' always) */
    public function __construct(public array $sizes, public int $width, public int $height) {}

    public function main(): string
    {
        return $this->sizes['main'];
    }
}
