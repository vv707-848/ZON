<?php

namespace App\Integrations\ImageHash;

/** A perceptual hash of a photo (16 hex chars): finds the same photo reused across listings. */
interface ImageHasher
{
    public function hash(string $binary): ?string;
}
