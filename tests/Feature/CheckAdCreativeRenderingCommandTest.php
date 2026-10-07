<?php

namespace Tests\Feature;

use App\Services\AdCreative\TextRenderingCapability;
use Tests\TestCase;

class CheckAdCreativeRenderingCommandTest extends TestCase
{
    public function test_the_default_bangla_font_is_bundled_with_its_license(): void
    {
        $this->assertFileExists(TextRenderingCapability::defaultFontPath());
        $this->assertFileExists(resource_path('fonts/ad-creative/OFL.txt'));
    }

    public function test_it_reports_not_ready_when_imagick_is_missing(): void
    {
        if (extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is installed here; covered by the missing-font test instead.');
        }

        $this->artisan('ad-creative:check-rendering')
            ->expectsOutputToContain('Imagick extension')
            ->expectsOutputToContain('This server cannot render Bangla ad creatives correctly yet.')
            ->assertFailed();

        $this->assertFalse(app(TextRenderingCapability::class)->supportsBangla());
    }

    public function test_a_missing_font_is_skipped_and_counts_as_not_ready(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is not installed here.');
        }

        $report = app(TextRenderingCapability::class)->report('/nonexistent/font.ttf');
        $shaping = collect($report)->firstWhere('check', 'Bangla shaping');

        $this->assertNull($shaping['ok']);
        $this->artisan('ad-creative:check-rendering', ['--font' => '/nonexistent/font.ttf'])->assertFailed();
    }

    public function test_render_without_imagick_warns_instead_of_crashing(): void
    {
        if (extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is installed here.');
        }

        $this->artisan('ad-creative:check-rendering', ['--render' => true])
            ->expectsOutputToContain('Sample not rendered')
            ->assertFailed();
    }
}
