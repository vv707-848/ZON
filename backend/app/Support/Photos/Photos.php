<?php

namespace App\Support\Photos;

use GdImage;

/**
 * Every photo is re-drawn at upload, never kept as it came from the phone:
 *
 * - **Upright:** the camera's EXIF orientation is applied to the pixels (GD ignores it otherwise).
 * - **No metadata:** the new JPEG carries none of the original's blocks, so a phone's location (GPS), model and
 *   date never reach a page or a search engine.
 * - **Three sizes** (longest side, JPEG quality), measured with GD on this server's PHP: a 12 MP photo gives
 *   main ≈ 390 KB, large ≈ 180 KB, card ≈ 63 KB, in ≈ 0.8 s and +64 MB of memory; the app's sample photos give a
 *   card of ≈ 50 KB (docs/data-model.md, «الصور»).
 *   - main 2048 · q82: the photo viewer (a phone screen at 3× is 1290 px wide).
 *   - large 1280 · q80: the website's gallery and link previews (WhatsApp takes an image under 600 KB, ≥ 300 px).
 *   - card 640 · q80: cards in lists.
 *
 * A transparent PNG is laid on white. Photos above config('zon.media.max_megapixels') are refused before decoding
 * (decoding takes width × height × 4 bytes; PHP's memory_limit is 256M in deploy/php/zon.ini).
 */
final class Photos
{
    /** @var array<string, array{0: int, 1: int}> size => [longest side, JPEG quality] */
    public const SIZES = ['main' => [2048, 82], 'large' => [1280, 80], 'card' => [640, 80]];

    /** @throws PhotoRejected */
    public static function clean(string $path): CleanPhoto
    {
        $info = @getimagesize($path);
        if ($info === false || ! in_array($info[2], self::readable(), true)) {
            throw new PhotoRejected('صيغة الصورة غير مدعومة. المقبول: JPG أو PNG'.(self::webp() ? ' أو WebP' : '').'.');
        }
        $limit = (float) config('zon.media.max_megapixels');
        if ($limit * 1_000_000 < $info[0] * $info[1]) {
            throw new PhotoRejected('أبعاد الصورة أكبر من المسموح ('.rtrim(rtrim(number_format($limit, 1), '0'), '.').' ميغابكسل). صغّرها ثم أعد رفعها.');
        }

        $image = @imagecreatefromstring((string) file_get_contents($path));
        if (! $image instanceof GdImage) {
            throw new PhotoRejected('تعذّرت قراءة الصورة. جرّب صورة أخرى.');
        }
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        imagesavealpha($image, true);
        // Down to the main size first: turning or flattening a full 36 MP photo would hold two copies in memory.
        $image = self::fit($image, self::SIZES['main'][0]);
        $image = $info[2] === IMAGETYPE_JPEG ? self::upright($image, self::orientation($path)) : self::onWhite($image);

        // Largest first, each size drawn from the one before it.
        $sizes = [];
        [$width, $height] = [0, 0];
        foreach (self::SIZES as $name => [$side, $quality]) {
            $image = self::fit($image, $side);
            if ($name === 'main') {
                [$width, $height] = [imagesx($image), imagesy($image)];
            }
            $sizes[$name] = self::jpeg($image, $quality);
        }

        return new CleanPhoto($sizes, $width, $height);
    }

    /** @return list<int> the image types this server's GD decodes (WebP only where GD was built with it) */
    public static function readable(): array
    {
        return array_values(array_filter([IMAGETYPE_JPEG, IMAGETYPE_PNG, self::webp() ? IMAGETYPE_WEBP : null]));
    }

    /** @return list<string> the extensions the upload rule accepts */
    public static function extensions(): array
    {
        return self::webp() ? ['jpg', 'jpeg', 'png', 'webp'] : ['jpg', 'jpeg', 'png'];
    }

    private static function webp(): bool
    {
        return (imagetypes() & IMG_WEBP) !== 0;
    }

    /** EXIF orientation 1–8 (1 when absent or unreadable). */
    private static function orientation(string $path): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }
        $exif = @exif_read_data($path, 'IFD0');
        $value = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $value >= 1 && $value <= 8 ? $value : 1;
    }

    /**
     * Applies an EXIF orientation to the pixels. imagerotate() turns anticlockwise: 270 is a quarter turn clockwise.
     * 5 (transpose) = mirror then a quarter turn anticlockwise · 7 (transverse) = mirror then a quarter turn clockwise.
     */
    private static function upright(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        if ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }
        $angle = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => 270,
            default => 0,
        };
        if ($angle !== 0) {
            $turned = imagerotate($image, $angle, 0);
            $image = $turned instanceof GdImage ? $turned : $image;
        }

        return $image;
    }

    /** Transparency becomes white (a JPEG has no alpha; GD would paint it black). */
    private static function onWhite(GdImage $image): GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $canvas = imagecreatetruecolor($w, $h);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, $w, $h);

        return $canvas;
    }

    /** The image with its longest side at most $side (never enlarged). */
    private static function fit(GdImage $image, int $side): GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1, $side / max($w, $h));
        if ($scale >= 1) {
            return $image;
        }
        $scaled = imagescale($image, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)), IMG_BICUBIC);

        return $scaled instanceof GdImage ? $scaled : $image;
    }

    private static function jpeg(GdImage $image, int $quality): string
    {
        imageinterlace($image, true);           // progressive: a first view before the whole file arrives
        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }
}
