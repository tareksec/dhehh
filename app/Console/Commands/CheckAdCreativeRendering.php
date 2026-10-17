<?php

namespace App\Console\Commands;

use App\Services\AdCreative\TextRenderingCapability;
use Illuminate\Console\Command;

/**
 * Run on the production container (Coolify terminal) to confirm the server
 * can draw Bangla ad creatives before the Content Creation tool relies on
 * it (plan 14, Phase 0). Read-only apart from the optional --render sample.
 */
class CheckAdCreativeRendering extends Command
{
    protected $signature = 'ad-creative:check-rendering
        {--font= : Path to a Bangla TTF to probe with (defaults to the bundled ad creative font)}
        {--render : Also write a sample PNG to storage/app/diagnostics for a visual check}';

    protected $description = 'Checks whether this server can render Bangla text into ad creative images.';

    public function handle(TextRenderingCapability $capability): int
    {
        $font = $this->option('font') ?: null;

        $this->table(['Check', 'Result', 'Detail'], array_map(fn (array $check): array => [
            $check['check'],
            match ($check['ok']) {
                true => 'OK',
                false => 'FAIL',
                null => 'SKIPPED',
            },
            $check['detail'],
        ], $capability->report($font)));

        if ($this->option('render')) {
            $this->renderSample($capability, $font);
        }

        if (! $capability->supportsBangla($font)) {
            $this->error('This server cannot render Bangla ad creatives correctly yet.');

            return self::FAILURE;
        }

        $this->info('This server can render Bangla ad creatives.');

        return self::SUCCESS;
    }

    private function renderSample(TextRenderingCapability $capability, ?string $font): void
    {
        if (! extension_loaded('imagick')) {
            $this->warn('Sample not rendered: the Imagick extension is not installed.');

            return;
        }

        if (! is_file($font ?? TextRenderingCapability::defaultFontPath())) {
            $this->warn('Sample not rendered: no font file to draw with.');

            return;
        }

        $path = storage_path('app/diagnostics/ad-creative-rendering.png');
        $capability->renderSample($path, $font);
        $this->info("Sample written to {$path}");
    }
}
