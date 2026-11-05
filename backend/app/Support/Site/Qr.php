<?php

namespace App\Support\Site;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * A QR code as inline SVG (chillerlan/php-qrcode): the advertising licence's link to REGA on a listing page, and
 * the ZATCA code of an invoice. Dark modules only, joined into one path, so it stays light and crisp.
 */
final class Qr
{
    public static function svg(string $data, string $label): string
    {
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
            'drawLightModules' => false,
            'connectPaths' => true,
            'cssClass' => 'qr',
        ]);
        $svg = (new QRCode($options))->render($data);

        // Read aloud by screen readers instead of being skipped.
        return (string) preg_replace('/^<svg /', '<svg role="img" aria-label="'.e($label).'" ', trim($svg), 1);
    }
}
