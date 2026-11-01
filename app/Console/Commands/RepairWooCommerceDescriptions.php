<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\WooCommerceDescriptionRepairService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RepairWooCommerceDescriptions extends Command
{
    protected $signature = 'woocommerce:repair-descriptions
        {company : Company slug to repair}
        {--apply : Write the changes (without it nothing is saved: dry run)}
        {--sku=* : Only these product SKUs (repeat the option for several)}
        {--base-url= : The old site address, when it is not the one in Storefront Settings}
        {--fill-blank : Also fill products that have no description yet}
        {--report= : Save the full before/after list as a JSON file}';

    protected $description = 'Restore the formatting (paragraphs, headings, lists, bold, links, tables) of descriptions imported from WooCommerce as plain text. Dry run unless --apply is given.';

    public function handle(WooCommerceDescriptionRepairService $service): int
    {
        $company = Company::query()->where('slug', $this->argument('company'))->first();

        if (! $company) {
            $this->error("Company '{$this->argument('company')}' not found.");

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $fillBlank = (bool) $this->option('fill-blank');

        try {
            $baseUrl = $service->baseUrlFor($company, $this->option('base-url'));
            $this->info("Reading product descriptions from {$baseUrl} ...");
            $woo = $service->fetchStoreProducts($baseUrl);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $plan = $service->plan($company, $woo, (array) $this->option('sku'), $fillBlank);

        $this->table(
            ['ID', 'SKU', 'Action', 'Why', 'Now', 'After'],
            array_map(fn (array $row): array => [
                $row['product_id'],
                $row['sku'],
                $row['action'] === 'update' ? 'FIX' : 'keep',
                $this->reasonLabel($row['reason']),
                $this->summary($row['before']),
                $row['action'] === 'update' ? $this->summary((string) $row['after']) : '',
            ], $plan),
        );

        $toFix = collect($plan)->where('action', 'update')->count();

        if ($this->option('report')) {
            file_put_contents((string) $this->option('report'), json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->line("Full list saved to {$this->option('report')}.");
        }

        if (! $apply) {
            $this->info("Dry run: {$toFix} of ".count($plan).' products would be fixed. Nothing was saved. Add --apply to write the changes.');

            return self::SUCCESS;
        }

        if ($toFix === 0) {
            $this->info('Nothing to fix.');

            return self::SUCCESS;
        }

        // Keep every replaced text so a change can be undone by hand.
        $backup = 'description-repair/'.$company->slug.'-'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put($backup, json_encode(
            collect($plan)->where('action', 'update')->map(fn (array $row): array => [
                'product_id' => $row['product_id'],
                'sku' => $row['sku'],
                'description_before' => $row['before'],
            ])->values()->all(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        $changed = $service->apply($company, $plan, $woo, $fillBlank);

        $this->info("Fixed {$changed} products. The previous texts are saved in storage/app/private/{$backup}.");

        return self::SUCCESS;
    }

    private function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'repair' => 'plain text from the old import',
            'fill' => 'empty, filled from the old site',
            'blank' => 'empty (use --fill-blank to fill)',
            'already-fixed' => 'already fixed',
            'formatted' => 'already formatted / edited in the editor',
            'nothing-to-fix' => 'one plain paragraph, nothing to restore',
            'edited' => 'text differs from the old site (edited by hand)',
            'no-source' => 'no description on the old site',
            'no-match' => 'not on the old site (SKU/slug)',
            default => $reason,
        };
    }

    private function summary(string $value): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($value)));

        return $text === '' ? '-' : mb_strimwidth($text, 0, 40, '…');
    }
}
