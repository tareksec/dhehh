<?php

namespace App\Services\AdCreative;

use Imagick;
use ImagickDraw;
use ImagickPixel;
use Throwable;

/**
 * Answers "can this server draw Bangla text into an image correctly?"
 * before the ad creative renderer relies on it (plan 14, Phase 0).
 *
 * GD's FreeType path draws Bangla glyph by glyph: the hasanta shows as a
 * separate mark, conjuncts never join and ি / ে land on the wrong side of
 * the consonant. Imagick shapes correctly only when ImageMagick was built
 * with the raqm delegate (HarfBuzz + FriBidi), which differs per build, so
 * this is checked on the actual server instead of assumed.
 */
class TextRenderingCapability
{
    public const DEFAULT_FONT = 'fonts/ad-creative/HindSiliguri-Bold.ttf';

    /**
     * A shaped conjunct (ক + ্ + ষ → ক্ষ) is one glyph, so it is much
     * narrower than its three code points drawn side by side. Measured on
     * 2026-10-03 with the bundled Hind Siliguri Bold: shaped (Imagick +
     * raqm) ≈ 0.51 of the unshaped sum, GD ≈ 0.81.
     */
    public const SHAPING_PROBE = 'ক্ষ';

    public const SHAPED_WIDTH_RATIO_MAX = 0.6;

    public const SAMPLE_LINES = [
        'স্বাস্থ্যকর ত্বকের জন্য সেরা ক্রিম',
        'কিছু ক্ষ র‍্য ৳১,২৫০',
        'Special Offer — 20% OFF',
    ];

    public static function defaultFontPath(): string
    {
        return resource_path(self::DEFAULT_FONT);
    }

    /**
     * `ok` is null when a check could not run (e.g. no font file).
     *
     * @return list<array{check: string, ok: bool|null, detail: string}>
     */
    public function report(?string $fontPath = null): array
    {
        $fontPath ??= self::defaultFontPath();

        $checks = [[
            'check' => 'GD extension',
            'ok' => extension_loaded('gd'),
            'detail' => 'Not usable for Bangla text (no complex-script shaping); fine for image compositing.',
        ]];

        if (! extension_loaded('imagick')) {
            $checks[] = ['check' => 'Imagick extension', 'ok' => false, 'detail' => 'Not installed. Bangla ad creatives cannot be rendered on this server.'];

            return $checks;
        }

        $checks[] = ['check' => 'Imagick extension', 'ok' => true, 'detail' => Imagick::getVersion()['versionString'] ?? 'unknown version'];

        $delegates = array_values(array_filter(preg_split('/\s+/', trim(Imagick::getConfigureOptions()['DELEGATES'] ?? '')) ?: []));
        $checks[] = [
            'check' => 'ImageMagick raqm delegate',
            'ok' => in_array('raqm', $delegates, true),
            'detail' => 'Delegates: '.(implode(' ', $delegates) ?: 'none reported'),
        ];

        $checks[] = $this->shapingCheck($fontPath);

        return $checks;
    }

    /**
     * True only when every Imagick-side check passed; a skipped shaping
     * probe (no font) counts as not ready.
     */
    public function supportsBangla(?string $fontPath = null): bool
    {
        foreach ($this->report($fontPath) as $check) {
            if ($check['check'] !== 'GD extension' && $check['ok'] !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * Draws SAMPLE_LINES with Imagick so a person can eyeball the result.
     */
    public function renderSample(string $outputPath, ?string $fontPath = null): void
    {
        $fontPath ??= self::defaultFontPath();

        $canvas = new Imagick;
        $canvas->newImage(1200, 120 * count(self::SAMPLE_LINES) + 40, new ImagickPixel('white'));
        $canvas->setImageFormat('png');

        foreach (self::SAMPLE_LINES as $index => $line) {
            $draw = $this->draw($fontPath, 56);
            $draw->setFillColor(new ImagickPixel('#141414'));
            $canvas->annotateImage($draw, 30, 100 + ($index * 120), 0, $line);
        }

        if (! is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0755, true);
        }

        $canvas->writeImage($outputPath);
        $canvas->clear();
    }

    /**
     * @return array{check: string, ok: bool|null, detail: string}
     */
    private function shapingCheck(string $fontPath): array
    {
        if (! is_file($fontPath)) {
            return ['check' => 'Bangla shaping', 'ok' => null, 'detail' => "Skipped: font file not found at {$fontPath}."];
        }

        try {
            $imagick = new Imagick;
            $draw = $this->draw($fontPath, 100);
            $width = fn (string $text): float => (float) $imagick->queryFontMetrics($draw, $text)['textWidth'];

            $shaped = $width(self::SHAPING_PROBE);
            $unshaped = array_sum(array_map($width, mb_str_split(self::SHAPING_PROBE)));
            $ratio = $unshaped > 0 ? $shaped / $unshaped : 1.0;
        } catch (Throwable $exception) {
            return ['check' => 'Bangla shaping', 'ok' => false, 'detail' => 'Probe failed: '.$exception->getMessage()];
        }

        return [
            'check' => 'Bangla shaping',
            'ok' => $ratio <= self::SHAPED_WIDTH_RATIO_MAX,
            'detail' => sprintf('Conjunct width ratio %.2f (shaped <= %.2f) using %s', $ratio, self::SHAPED_WIDTH_RATIO_MAX, basename($fontPath)),
        ];
    }

    private function draw(string $fontPath, float $size): ImagickDraw
    {
        $draw = new ImagickDraw;
        $draw->setFont($fontPath);
        $draw->setFontSize($size);
        $draw->setTextEncoding('UTF-8');

        return $draw;
    }
}
