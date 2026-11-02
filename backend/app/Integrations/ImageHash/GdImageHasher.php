<?php

namespace App\Integrations\ImageHash;

/** dHash with GD: 9×8 greyscale, one bit per left-right comparison → 64 bits. */
class GdImageHasher implements ImageHasher
{
    public function name(): string
    {
        return 'gd';
    }

    public function hash(string $binary): ?string
    {
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return null;
        }
        $small = imagecreatetruecolor(9, 8);
        imagecopyresampled($small, $image, 0, 0, 0, 0, 9, 8, imagesx($image), imagesy($image));

        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $bits .= $this->grey($small, $x, $y) > $this->grey($small, $x + 1, $y) ? '1' : '0';
            }
        }

        return implode('', array_map(fn (string $nibble) => dechex((int) bindec($nibble)), str_split($bits, 4)));
    }

    private function grey(\GdImage $image, int $x, int $y): float
    {
        $rgb = imagecolorat($image, $x, $y);

        return 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
    }
}
