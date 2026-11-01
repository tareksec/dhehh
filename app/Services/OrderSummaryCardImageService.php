<?php

namespace App\Services;

use App\Models\Order;
use Imagick;
use ImagickDraw;
use ImagickPixel;
use Throwable;

/**
 * Draws the order summary card as a PNG on the server, for the automatic
 * WhatsApp message (OrderWhatsAppCardService). It is the same layout the
 * browser draws on the order page (resources/views/filament/orders/
 * summary-card.blade.php) from the same data (OrderSummaryCardService).
 *
 * All text goes through ImageMagick's Pango coder, which shapes Bangla
 * correctly (joined conjuncts, vowel signs on the right side). GD cannot do
 * that and Imagick's own text drawing needs the raqm delegate, so this class
 * refuses to draw (canRender() is false) on a server without the Pango coder
 * instead of sending a card with broken Bangla. The font is the bundled Hind
 * Siliguri, registered through a private fontconfig file.
 */
class OrderSummaryCardImageService
{
    protected const WIDTH = 1080;

    protected const NAVY = '#0f2a43';

    protected const ACCENT = '#ff6a00';

    protected const INK = '#0f172a';

    protected const MUTED = '#64748b';

    protected const LINE = '#e2e8f0';

    protected const SOFT = '#f8fafc';

    protected const SOFT2 = '#f1f5f9';

    protected const GREEN = '#16a34a';

    /** The Bangla conjunct ক্ষ is one narrow glyph when shaped; unshaped it is about as wide as কষ plus a mark. */
    protected const SHAPED_RATIO_MAX = 0.9;

    protected static ?string $problem = null;

    protected static bool $checked = false;

    public function __construct(protected OrderSummaryCardService $cards) {}

    /** True when this server can draw the card with correct Bangla. */
    public function canRender(): bool
    {
        return $this->problem() === null;
    }

    /** Why this server cannot draw the card, or null when it can. */
    public function problem(): ?string
    {
        if (self::$checked) {
            return self::$problem;
        }

        self::$checked = true;

        try {
            if (! extension_loaded('imagick')) {
                return self::$problem = 'The PHP imagick extension is not installed.';
            }

            if (! in_array('PANGO', Imagick::queryFormats('PANGO'), true)) {
                return self::$problem = 'ImageMagick has no Pango support (on Debian/Ubuntu: install libmagickcore-6.q16-6-extra).';
            }

            foreach (['Regular', 'Bold'] as $weight) {
                if (! is_file($this->fontFile($weight))) {
                    return self::$problem = 'The bundled font HindSiliguri-'.$weight.'.ttf is missing.';
                }
            }

            $conjunct = $this->measure('ক্ষ');
            $plain = $this->measure('কষ');

            if ($plain < 1 || $conjunct / $plain > self::SHAPED_RATIO_MAX) {
                return self::$problem = 'This server draws Bangla letters without joining them (no HarfBuzz shaping in Pango).';
            }

            return self::$problem = null;
        } catch (Throwable $exception) {
            return self::$problem = 'The card image check failed: '.$exception->getMessage();
        }
    }

    /** PNG bytes of the card for this order, or null when this server cannot draw it. */
    public function render(Order $order): ?string
    {
        if (! $this->canRender()) {
            return null;
        }

        return $this->renderCard($this->cards->build($order));
    }

    /**
     * @param  array<string, mixed>  $card  OrderSummaryCardService::build()
     */
    public function renderCard(array $card): string
    {
        return $this->renderCardWithLinks($card)['png'];
    }

    /**
     * The card as a PNG plus where its clickable buttons are. A row with the key "track_button" (only the PDF card
     * has one, see OrderSummaryCardService::buildForPdf()) is drawn as a highlighted button in the place the tracking
     * code would sit, and its value (the courier's tracking page) comes back in "links" with the button's box in
     * pixels, so OrderSummaryCardPdfService can put a link over it. The card is as tall as its content needs.
     *
     * @param  array<string, mixed>  $card  OrderSummaryCardService::build() or buildForPdf()
     * @return array{png: string, width: int, height: int, links: list<array{x: float, y: float, w: float, h: float, url: string}>}
     */
    public function renderCardWithLinks(array $card): array
    {
        $this->useFonts();
        $links = [];

        $w = self::WIDTH;
        $pad = 56;
        $inner = $w - $pad * 2;
        $ops = [];
        $rect = function (float $x, float $y, float $width, float $height, string $color, float $r = 0, ?string $stroke = null) use (&$ops): void {
            $ops[] = ['rect', $x, $y, $width, $height, $color, $r, $stroke];
        };
        // Text is rendered to a small transparent image first; it is placed by its top-left corner.
        $text = function (string $value, float $size, bool $bold, string $color, float $x, float $y, string $align = 'left', ?int $maxWidth = null, float $spacing = 0) use (&$ops): array {
            $block = $this->textBlock($value, $size, $bold, $color, $maxWidth, $spacing, $align);
            $left = match ($align) {
                'right' => $x - $block['w'],
                'center' => $x - $block['w'] / 2,
                default => $x,
            };
            $ops[] = ['image', $left, $y, $block['im'], null, 0, null];

            return $block;
        };
        // Measures a text block without drawing it.
        $height = fn (string $value, float $size, bool $bold, ?int $maxWidth = null): float => $this->textBlock($value, $size, $bold, '#000000', $maxWidth)['h'];

        // 1. Header: logo (or the company name) on the left, the company's contact details (phone,
        // e-mail, website, address) on the right - the only place they appear.
        $top = 44;
        $contact = array_slice((array) ($card['contact'] ?? []), 0, 3);
        $address = trim((string) ($card['address'] ?? ''));
        $contactH = array_sum(array_map(fn (string $line): float => $height($line, 26, false) + 2, $contact))
            + ($address !== '' ? $height($address, 24, false, 430) : 0);
        $headerH = max(132, $contactH + 8);
        $logo = $this->dataUriImage($card['logo'] ?? null);

        if ($logo) {
            $scale = min(460 / $logo->getImageWidth(), 120 / $logo->getImageHeight(), 2);
            $lw = (int) round($logo->getImageWidth() * $scale);
            $lh = (int) round($logo->getImageHeight() * $scale);
            $logo->resizeImage($lw, $lh, Imagick::FILTER_LANCZOS, 1);
            $ops[] = ['image', $pad, $top + ($headerH - $lh) / 2, $logo, null, 0, null];
        } else {
            $nameH = $height((string) $card['company'], 46, true, 540);
            $text((string) $card['company'], 46, true, self::NAVY, $pad, $top + ($headerH - $nameH) / 2, 'left', 540);
        }

        $cy = $top + ($headerH - $contactH) / 2;

        foreach ($contact as $line) {
            $block = $text($line, 26, false, self::MUTED, $w - $pad, $cy, 'right');
            $cy += $block['h'] + 2;
        }

        if ($address !== '') {
            $text($address, 24, false, self::MUTED, $w - $pad, $cy, 'right', 430);
        }

        // 2. Title band with the invoice number.
        $bandY = $top + $headerH + 14;
        $rect(0, $bandY, $w, 76, self::NAVY);
        $rect(0, $bandY + 76, $w, 6, self::ACCENT);
        $titleH = $height('ORDER', 28, true);
        $text(mb_strtoupper((string) $card['title']), 28, true, '#ffffff', $pad, $bandY + (76 - $titleH) / 2, 'left', null, 3);
        $text($card['labels']['invoice'].'  '.$card['invoice'], 28, true, '#ffffff', $w - $pad, $bandY + (76 - $titleH) / 2, 'right');

        // 3. Order details and customer details, side by side.
        $gap = 28;
        $cardW = (int) (($inner - $gap) / 2);
        $cardPad = 26;
        $labelW = 162;
        $cardsY = $bandY + 76 + 6 + 34;
        $valueW = $cardW - $cardPad * 2 - $labelW;

        $buttonH = 62;
        $rowHeight = function (array $row) use ($height, $cardW, $cardPad, $valueW, $buttonH): float {
            if ($row['key'] === 'track_button') {
                return $buttonH + 14;
            }

            if ($row['key'] === 'address') {
                return $height($row['label'], 23, false) + $height((string) $row['value'], 27, false, $cardW - $cardPad * 2) + 14;
            }

            return max(34, $height((string) $row['value'], 27, false, $valueW)) + 14;
        };
        $rowsHeight = fn (array $rows): float => array_sum(array_map($rowHeight, $rows));
        $payRowH = 58;
        $cardH = 70 + max($rowsHeight($card['order_rows']) + $payRowH, $rowsHeight($card['customer_rows'])) + 6;

        $drawCard = function (float $x, string $title, array $rows, ?array $payment) use ($rect, $text, $height, $cardsY, $cardW, $cardH, $cardPad, $labelW, $valueW, $buttonH, &$links): void {
            $rect($x, $cardsY, $cardW, $cardH, self::SOFT, 18, self::LINE);
            $rect($x + $cardPad, $cardsY + 22, 6, 26, self::ACCENT, 3);
            $text(mb_strtoupper($title), 22, true, self::NAVY, $x + $cardPad + 20, $cardsY + 22 + (26 - $height('A', 22, true)) / 2, 'left', null, 2);
            $ry = $cardsY + 70;

            foreach ($rows as $row) {
                // The courier tracking button: it sits where the tracking code would, and the code itself is not shown.
                if ($row['key'] === 'track_button') {
                    $buttonW = $cardW - $cardPad * 2;
                    $rect($x + $cardPad, $ry, $buttonW, $buttonH, self::ACCENT, $buttonH / 2);
                    $text((string) $row['label'], 28, true, '#ffffff', $x + $cardPad + $buttonW / 2, $ry + ($buttonH - $height((string) $row['label'], 28, true)) / 2, 'center');
                    $links[] = ['x' => (float) ($x + $cardPad), 'y' => (float) $ry, 'w' => (float) $buttonW, 'h' => (float) $buttonH, 'url' => (string) $row['value']];
                    $ry += $buttonH + 14;

                    continue;
                }

                if ($row['key'] === 'address') {
                    $label = $text($row['label'], 23, false, self::MUTED, $x + $cardPad, $ry);
                    $value = $text((string) $row['value'], 27, false, self::INK, $x + $cardPad, $ry + $label['h'], 'left', $cardW - $cardPad * 2);
                    $ry += $label['h'] + $value['h'] + 14;

                    continue;
                }

                $bold = $row['key'] === 'customer';
                $rowH = max(34, $height((string) $row['value'], $bold ? 30 : 27, $bold, $valueW));
                $text($row['label'], 23, false, self::MUTED, $x + $cardPad, $ry + (34 - $height($row['label'], 23, false)) / 2);
                $text((string) $row['value'], $bold ? 30 : 27, $bold, self::INK, $x + $cardPad + $labelW, $ry, 'left', $valueW);
                $ry += $rowH + 14;
            }

            if ($payment) {
                [$background, $foreground] = match ($payment['key']) {
                    'paid' => ['#dcfce7', '#15803d'],
                    'partial' => ['#fef3c7', '#b45309'],
                    'unpaid' => ['#fee2e2', '#b91c1c'],
                    default => ['#e2e8f0', self::INK],
                };
                $label = $this->textBlock($payment['label'], 22, true, $foreground);
                $pillW = $label['w'] + 44;
                $text($payment['label_title'], 23, false, self::MUTED, $x + $cardPad, $ry + (42 - $height($payment['label_title'], 23, false)) / 2);
                $rect($x + $cardPad + $labelW, $ry, $pillW, 42, $background, 21);
                $text($payment['label'], 22, true, $foreground, $x + $cardPad + $labelW + $pillW / 2, $ry + (42 - $label['h']) / 2, 'center');
            }
        };
        $drawCard($pad, (string) $card['labels']['order_details'], $card['order_rows'], $card['payment'] + ['label_title' => (string) $card['labels']['payment']]);
        $drawCard($pad + $cardW + $gap, (string) $card['labels']['customer_details'], $card['customer_rows'], null);

        $y = $cardsY + $cardH + 34;

        // 4. Items table: the picture of every product the customer ordered.
        if ($card['items'] !== []) {
            $priceR = 745;
            $qtyC = 830;
            $amountR = $w - $pad - 24;
            $headH = $height('ITEM', 21, true);
            $rect($pad, $y, $inner, 60, self::NAVY, 14);

            foreach ([
                [mb_strtoupper((string) $card['labels']['item']), $pad + 24, 'left'],
                [mb_strtoupper((string) $card['labels']['price']), $priceR, 'right'],
                [mb_strtoupper((string) $card['labels']['quantity']), $qtyC, 'center'],
                [mb_strtoupper((string) $card['labels']['amount']), $amountR, 'right'],
            ] as [$label, $x, $align]) {
                $text($label, 21, true, '#ffffff', $x, $y + (60 - $headH) / 2, $align, null, 1.5);
            }

            $y += 60;
            $thumb = 96;
            $hasThumbs = (bool) array_filter($card['items'], fn (array $item): bool => filled($item['image']));
            $textX = $pad + 24 + ($hasThumbs ? $thumb + 20 : 0);
            $textW = $priceR - 190 - $textX;

            foreach ($card['items'] as $i => $item) {
                $nameH = $height((string) $item['name'], 28, true, $textW);
                $rowH = max($nameH, $hasThumbs ? $thumb : 0) + 32;

                if ($i % 2 === 1) {
                    $rect($pad, $y, $inner, $rowH, self::SOFT);
                }

                if ($hasThumbs) {
                    $ops[] = ['thumb', $pad + 24, $y + ($rowH - $thumb) / 2, $this->dataUriImage($item['image']), $thumb, 12, self::LINE];
                }

                $text((string) $item['name'], 28, true, self::INK, $textX, $y + ($rowH - $nameH) / 2, 'left', $textW);
                $price = $this->textBlock((string) $item['price'], 26, false, self::MUTED);
                $qty = $this->textBlock((string) $item['quantity'], 28, true, self::INK);
                $amount = $this->textBlock((string) $item['amount'], 28, true, self::INK);
                $mid = $y + $rowH / 2;
                $ops[] = ['image', $priceR - $price['w'], $mid - $price['h'] / 2, $price['im'], null, 0, null];
                $ops[] = ['image', $qtyC - $qty['w'] / 2, $mid - $qty['h'] / 2, $qty['im'], null, 0, null];
                $ops[] = ['image', $amountR - $amount['w'], $mid - $amount['h'] / 2, $amount['im'], null, 0, null];
                $y += $rowH;
                $rect($pad, $y - 1, $inner, 2, self::LINE);
            }

            $y += 30;
        }

        // 5. Order note on the left, totals on the right.
        $totalsW = 440;
        $totalsX = $w - $pad - $totalsW;
        $sideW = (int) ($totalsX - $pad - 28);
        $sideCards = [];

        if (filled($card['note'] ?? null)) {
            $sideCards[] = [(string) $card['labels']['note'], (string) $card['note']];
        }

        $sy = $y;

        foreach ($sideCards as [$title, $value]) {
            $valueH = $height($value, 25, false, $sideW - $cardPad * 2);
            $h = 62 + $valueH + 22;
            $rect($pad, $sy, $sideW, $h, self::SOFT, 18, self::LINE);
            $rect($pad + $cardPad, $sy + 22, 6, 26, self::ACCENT, 3);
            $text(mb_strtoupper($title), 22, true, self::NAVY, $pad + $cardPad + 20, $sy + 22 + (26 - $height('A', 22, true)) / 2, 'left', null, 2);
            $text($value, 25, false, self::INK, $pad + $cardPad, $sy + 62, 'left', $sideW - $cardPad * 2);
            $sy += $h + 18;
        }

        $sideH = $sideCards === [] ? 0 : $sy - $y - 18;
        $ty = $y;
        $due = null;

        foreach ($card['totals'] as $row) {
            if ($row['key'] === 'due') {
                $due = $row;

                continue;
            }

            if ($row['key'] === 'total') {
                $rect($totalsX, $ty + 4, $totalsW, 2, self::LINE);
                $ty += 14;
                $label = $text((string) $row['label'], 32, true, self::NAVY, $totalsX + 8, $ty);
                $text((string) $row['value'], 32, true, self::NAVY, $totalsX + $totalsW - 8, $ty, 'right');
                $ty += max(56, $label['h']);

                continue;
            }

            $label = $text((string) $row['label'], 26, false, self::MUTED, $totalsX + 8, $ty);
            $text((string) $row['value'], 27, true, $row['key'] === 'paid' ? self::GREEN : self::INK, $totalsX + $totalsW - 8, $ty + 2, 'right');
            $ty += max(46, $label['h']);
        }

        if ($due) {
            $ty += 8;
            $rect($totalsX, $ty, $totalsW, 88, ($card['payment']['key'] ?? '') === 'paid' ? self::GREEN : self::ACCENT, 18);
            $label = $this->textBlock((string) $due['label'], 30, true, '#ffffff');
            $value = $this->textBlock((string) $due['value'], 40, true, '#ffffff');
            $ops[] = ['image', $totalsX + 28, $ty + (88 - $label['h']) / 2, $label['im'], null, 0, null];
            $ops[] = ['image', $totalsX + $totalsW - 28 - $value['w'], $ty + (88 - $value['h']) / 2, $value['im'], null, 0, null];
            $ty += 88;
        }

        $y += max($sideH, $ty - $y) + 44;

        // 6. Footer: thank you.
        $rect($pad, $y, $inner, 2, self::LINE);
        $y += 26;
        $block = $text((string) $card['labels']['thanks'], 31, true, self::NAVY, $w / 2, $y, 'center');
        $y += $block['h'];

        $y += 36;
        $rect(0, $y, $w, 14, self::NAVY);
        $y += 14;

        $cardHeight = (int) ceil($y);

        return [
            'png' => $this->compose($ops, $cardHeight),
            'width' => self::WIDTH,
            'height' => $cardHeight,
            'links' => $links,
        ];
    }

    /**
     * @param  list<array<int, mixed>>  $ops
     */
    protected function compose(array $ops, int $height): string
    {
        $canvas = new Imagick();
        $canvas->newImage(self::WIDTH, $height, new ImagickPixel('white'));
        $canvas->setImageFormat('png');

        foreach ($ops as $op) {
            [$kind, $x, $y] = $op;

            if ($kind === 'rect') {
                [, , , $width, $heightPx, $color, $radius, $strokeColor] = $op;
                $draw = new ImagickDraw();
                $draw->setFillColor(new ImagickPixel($color));

                if ($strokeColor) {
                    $draw->setStrokeColor(new ImagickPixel($strokeColor));
                    $draw->setStrokeWidth(2);
                    $draw->setStrokeAntialias(true);
                } else {
                    $draw->setStrokeOpacity(0);
                }

                if ($radius > 0) {
                    $draw->roundRectangle($x, $y, $x + $width - 1, $y + $heightPx - 1, $radius, $radius);
                } else {
                    $draw->rectangle($x, $y, $x + $width - 1, $y + $heightPx - 1);
                }

                $canvas->drawImage($draw);

                continue;
            }

            if ($kind === 'thumb') {
                [, , , $image, $size, $radius, $border] = $op;
                $this->drawThumb($canvas, $image, (int) round($x), (int) round($y), (int) $size, (int) $radius, (string) $border);

                continue;
            }

            /** @var Imagick $image */
            $image = $op[3];
            $canvas->compositeImage($image, Imagick::COMPOSITE_OVER, (int) round($x), (int) round($y));
        }

        $png = $canvas->getImageBlob();
        $canvas->clear();

        return $png;
    }

    /** A product picture as a rounded square with a thin border (a plain grey box when it has none). */
    protected function drawThumb(Imagick $canvas, ?Imagick $image, int $x, int $y, int $size, int $radius, string $border): void
    {
        $tile = new Imagick();
        $tile->newImage($size, $size, new ImagickPixel(self::SOFT2));

        if ($image) {
            $image->resizeImage($size, $size, Imagick::FILTER_LANCZOS, 1);
            $tile->compositeImage($image, Imagick::COMPOSITE_OVER, 0, 0);
        }

        $mask = new Imagick();
        $mask->newImage($size, $size, new ImagickPixel('transparent'));
        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel('white'));
        $draw->roundRectangle(0, 0, $size - 1, $size - 1, $radius, $radius);
        $mask->drawImage($draw);

        $tile->setImageFormat('png');
        $tile->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
        $tile->compositeImage($mask, Imagick::COMPOSITE_DSTIN, 0, 0);
        $canvas->compositeImage($tile, Imagick::COMPOSITE_OVER, $x, $y);

        $outline = new ImagickDraw();
        $outline->setFillOpacity(0);
        $outline->setStrokeColor(new ImagickPixel($border));
        $outline->setStrokeWidth(2);
        $outline->roundRectangle($x, $y, $x + $size - 1, $y + $size - 1, $radius, $radius);
        $canvas->drawImage($outline);
    }

    /**
     * One piece of text as a transparent image (Pango shapes it). With a max
     * width the text wraps inside that width.
     *
     * @return array{im: Imagick, w: int, h: int}
     */
    protected function textBlock(string $value, float $size, bool $bold, string $color, ?int $maxWidth = null, float $spacing = 0, string $align = 'left'): array
    {
        $image = new Imagick();
        $image->setBackgroundColor(new ImagickPixel('transparent'));

        if ($maxWidth) {
            $image->setSize($maxWidth, 0);
            $image->setOption('pango:align', $align === 'center' ? 'center' : ($align === 'right' ? 'right' : 'left'));
        }

        $markup = sprintf(
            '<span font="Hind Siliguri %s%dpx" foreground="%s"%s>%s</span>',
            $bold ? 'Bold ' : '',
            (int) round($size),
            $color,
            $spacing > 0 ? ' letter_spacing="'.(int) round($spacing * 1024).'"' : '',
            htmlspecialchars($value === '' ? ' ' : $value, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
        );
        $image->readImage('pango:'.$markup);

        return ['im' => $image, 'w' => $image->getImageWidth(), 'h' => $image->getImageHeight()];
    }

    protected function measure(string $value): int
    {
        $this->useFonts();

        return $this->textBlock($value, 60, true, '#000000')['w'];
    }

    protected function dataUriImage(mixed $dataUri): ?Imagick
    {
        if (! is_string($dataUri) || ! str_contains($dataUri, 'base64,')) {
            return null;
        }

        try {
            $image = new Imagick();
            $image->readImageBlob((string) base64_decode(substr($dataUri, strpos($dataUri, 'base64,') + 7)));
            $image->setImageBackgroundColor(new ImagickPixel('transparent'));

            return $image;
        } catch (Throwable) {
            return null;
        }
    }

    protected function fontFile(string $weight): string
    {
        return resource_path('fonts/ad-creative/HindSiliguri-'.$weight.'.ttf');
    }

    /**
     * Points fontconfig at the bundled font before Pango first needs it, so
     * the card looks the same on every server (the font need not be
     * installed system-wide).
     */
    protected function useFonts(): void
    {
        $directory = storage_path('app/order-card-fonts');

        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $conf = $directory.'/fonts.conf';
        $xml = '<?xml version="1.0"?><!DOCTYPE fontconfig SYSTEM "fonts.dtd"><fontconfig>'
            .'<include ignore_missing="yes">/etc/fonts/fonts.conf</include>'
            .'<dir>'.htmlspecialchars(dirname($this->fontFile('Regular')), ENT_XML1).'</dir>'
            .'<cachedir>'.htmlspecialchars($directory.'/cache', ENT_XML1).'</cachedir></fontconfig>';

        if (! is_file($conf) || file_get_contents($conf) !== $xml) {
            @file_put_contents($conf, $xml);
        }

        putenv('FONTCONFIG_FILE='.$conf);
    }
}
