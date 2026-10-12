<?php

namespace App\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Throwable;

/**
 * A QR code as an inline SVG data URI, drawn on the server (chillerlan/php-qrcode,
 * which Filament itself depends on) so the reseller's store link never goes to
 * a third-party QR service. Returns null if the code cannot be drawn, so the
 * page simply hides the QR instead of failing.
 */
class QrCodeSvg
{
    public static function dataUri(string $text): ?string
    {
        if (trim($text) === '') {
            return null;
        }

        try {
            $svg = (new QRCode(new QROptions([
                'outputType' => QROutputInterface::MARKUP_SVG,
                'outputBase64' => false,
                'eccLevel' => EccLevel::M,
                'addQuietzone' => true,
                'quietzoneSize' => 1,
                'connectPaths' => true,
                'drawLightModules' => false,
                'svgAddXmlHeader' => false,
                'drawCircularModules' => false,
                'moduleValues' => [
                    // Dark modules only; the card behind the image is white.
                    QRMatrix::IS_DARK => '#111827',
                ],
            ])))->render($text);
        } catch (Throwable) {
            return null;
        }

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
