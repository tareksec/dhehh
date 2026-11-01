<?php

namespace App\Services;

use App\Models\Order;
use Imagick;
use Throwable;

/**
 * The order summary card as a one-page PDF (owner request 2026-11-01).
 *
 * The page is the card itself: OrderSummaryCardImageService draws it (with correct Bangla, as tall as its content
 * needs, so a three-product order is longer than a one-product one) and this class wraps that picture in a PDF whose
 * page has exactly the card's size. The courier tracking button is part of the picture, and a link is placed over it,
 * so a tap on the button opens the courier's tracking page; the tracking code itself is not on the card.
 *
 * The PDF is written here directly (one page, one picture, one link per button) instead of through a PDF library:
 * the card is already a finished picture, and a library's own text drawing would not shape Bangla.
 */
class OrderSummaryCardPdfService
{
    /** PDF points per card pixel: the 1080 px wide card is a 540 pt (7.5 in) wide page, drawn at 144 dpi. */
    public const POINTS_PER_PIXEL = 0.5;

    public function __construct(
        protected OrderSummaryCardService $cards,
        protected OrderSummaryCardImageService $images,
    ) {}

    /** True when this server can draw the card (Imagick with Pango, see OrderSummaryCardImageService). */
    public function canRender(): bool
    {
        return $this->images->canRender() && function_exists('gzcompress');
    }

    /** Why this server cannot make the PDF, or null when it can. */
    public function problem(): ?string
    {
        return $this->images->problem() ?? (function_exists('gzcompress') ? null : 'The PHP zlib extension is not installed.');
    }

    /** The PDF bytes for this order, or null when this server cannot draw the card. */
    public function render(Order $order): ?string
    {
        if (! $this->canRender()) {
            return null;
        }

        $drawn = $this->images->renderCardWithLinks($this->cards->buildForPdf($order));

        return $this->pdf($drawn['png'], $drawn['width'], $drawn['height'], $drawn['links'], (string) $order->order_number);
    }

    /**
     * One PDF page with the picture filling it and a link over each button.
     *
     * @param  list<array{x: float, y: float, w: float, h: float, url: string}>  $links  boxes in picture pixels, from the top left
     */
    public function pdf(string $png, int $width, int $height, array $links = [], string $title = ''): string
    {
        $rgb = $this->rgbPixels($png, $width, $height);
        $pageWidth = $this->points($width);
        $pageHeight = $this->points($height);
        $image = gzcompress($rgb, 6);

        $annotationIds = [];
        $objects = [];
        $nextId = 6;

        foreach ($links as $link) {
            $annotationIds[] = $nextId;
            $left = $this->points($link['x']);
            $right = $this->points($link['x'] + $link['w']);
            // PDF counts from the bottom of the page, the card from the top.
            $bottom = $this->points($height - ($link['y'] + $link['h']));
            $top = $this->points($height - $link['y']);
            $objects[$nextId++] = '<< /Type /Annot /Subtype /Link /Rect ['.$left.' '.$bottom.' '.$right.' '.$top.'] /Border [0 0 0] '
                .'/A << /S /URI /URI ('.$this->pdfString($this->asciiUrl($link['url'])).') >> >>';
        }

        $content = 'q '.$pageWidth.' 0 0 '.$pageHeight.' 0 0 cm /Im0 Do Q';
        $annots = $annotationIds === [] ? '' : ' /Annots ['.implode(' ', array_map(fn (int $id): string => $id.' 0 R', $annotationIds)).']';

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.$pageWidth.' '.$pageHeight.'] /Resources << /XObject << /Im0 5 0 R >> >> /Contents 4 0 R'.$annots.' >>';
        $objects[4] = '<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream";
        $objects[5] = '<< /Type /XObject /Subtype /Image /Width '.$width.' /Height '.$height.' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /Length '.strlen($image).' >>'."\nstream\n".$image."\nendstream";
        ksort($objects);

        $info = '';

        if ($title !== '') {
            $objects[$nextId] = '<< /Title ('.$this->pdfString($this->asciiText('Order summary '.$title)).') /Producer (Business Dashboard) >>';
            $info = ' /Info '.$nextId.' 0 R';
            $nextId++;
        }

        $out = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= $id." 0 obj\n".$body."\nendobj\n";
        }

        $xref = strlen($out);
        $out .= "xref\n0 ".$nextId."\n0000000000 65535 f \n";

        for ($id = 1; $id < $nextId; $id++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        $out .= "trailer\n<< /Size ".$nextId.' /Root 1 0 R'.$info." >>\nstartxref\n".$xref."\n%%EOF\n";

        return $out;
    }

    /** Raw 8-bit RGB of the picture on white (the card has no transparency worth keeping), width x height x 3 bytes. */
    protected function rgbPixels(string $png, int $width, int $height): string
    {
        $image = new Imagick;

        try {
            $image->readImageBlob($png);
            $image->setImageBackgroundColor('white');
            $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            $image->setImageColorspace(Imagick::COLORSPACE_SRGB);
            $image->setImageDepth(8);
            $image->setImageFormat('rgb');
            $bytes = $image->getImageBlob();
        } catch (Throwable $exception) {
            throw new \RuntimeException('The card picture could not be read: '.$exception->getMessage(), 0, $exception);
        } finally {
            $image->clear();
        }

        if (strlen($bytes) !== $width * $height * 3) {
            throw new \RuntimeException('The card picture is not '.$width.' x '.$height.' pixels.');
        }

        return $bytes;
    }

    protected function points(int|float $pixels): string
    {
        return rtrim(rtrim(number_format($pixels * self::POINTS_PER_PIXEL, 2, '.', ''), '0'), '.') ?: '0';
    }

    /** A PDF text string in parentheses: backslash and parentheses escaped. */
    protected function pdfString(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }

    /** Printable ASCII only (a PDF title that is not UTF-16 encoded must not carry anything else). */
    protected function asciiText(string $value): string
    {
        return (string) preg_replace('/[^\x20-\x7E]/', '', $value);
    }

    /** Only printable ASCII goes into a PDF link: anything else is percent-encoded, spaces too. */
    protected function asciiUrl(string $value): string
    {
        return (string) preg_replace_callback('/[^\x21-\x7E]/', fn (array $match): string => rawurlencode($match[0]), $value);
    }
}
