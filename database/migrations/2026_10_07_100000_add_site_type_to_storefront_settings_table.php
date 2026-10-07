<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One company can now run several storefronts, each with its OWN storefront
 * settings row: the Blade storefront (the reseller store) and the headless
 * Next.js site (the main storefront). A row says which one it is in
 * `site_type`; every existing row is a Blade row, so nothing changes for the
 * code that already reads "the company's storefront setting".
 *
 * A company that had already switched the Next.js site on (or given it a
 * domain) on its Blade row gets a separate Next.js row: a full copy of the
 * Blade settings (so the new site starts populated and can then be
 * customised on its own) that carries the Next.js switch and domain, which
 * are cleared from the Blade row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->string('site_type', 20)->default('blade')->after('company_id');
        });

        // The new index goes in first: the company_id foreign key needs an
        // index that starts with company_id at every moment.
        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->unique(['company_id', 'site_type']);
        });

        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->dropUnique(['company_id']);
        });

        // Which storefront an order was placed on / a payment was started
        // from, so the payment webhooks and Meta tracking read that site's
        // gateway and pixel. NULL means the company's Blade storefront (every
        // record that exists today).
        foreach (['orders', 'storefront_payments'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('storefront_setting_id')->nullable()->constrained('storefront_settings')->nullOnDelete();
            });
        }

        $this->splitExistingNextjsSites();
    }

    public function down(): void
    {
        // Fold each Next.js row's switch and domain back onto its Blade row,
        // then drop the Next.js rows (their other settings are copies).
        DB::table('storefront_settings')->where('site_type', 'nextjs')->orderBy('id')->each(function (object $row): void {
            DB::table('storefront_settings')->where('id', $row->id)->update([
                'nextjs_site_enabled' => false,
                'nextjs_site_domain' => null,
                'nextjs_site_domain_verified' => false,
            ]);

            DB::table('storefront_settings')
                ->where('company_id', $row->company_id)
                ->where('site_type', 'blade')
                ->update([
                    'nextjs_site_enabled' => $row->nextjs_site_enabled,
                    'nextjs_site_domain' => $row->nextjs_site_domain,
                    'nextjs_site_domain_verified' => $row->nextjs_site_domain_verified,
                ]);
        });

        foreach (['orders', 'storefront_payments'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('storefront_setting_id');
            });
        }

        DB::table('storefront_settings')->where('site_type', 'nextjs')->delete();

        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->unique('company_id');
        });

        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'site_type']);
        });

        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->dropColumn('site_type');
        });
    }

    private function splitExistingNextjsSites(): void
    {
        DB::table('storefront_settings')
            ->where('site_type', 'blade')
            ->where(fn ($query) => $query->where('nextjs_site_enabled', true)->orWhereNotNull('nextjs_site_domain'))
            ->orderBy('id')
            ->get()
            ->each(function (object $blade): void {
                $nextjs = (array) $blade;
                unset($nextjs['id']);
                $nextjs['site_type'] = 'nextjs';

                // `nextjs_site_domain` is unique, so it leaves the Blade row first.
                DB::table('storefront_settings')->where('id', $blade->id)->update([
                    'nextjs_site_enabled' => false,
                    'nextjs_site_domain' => null,
                    'nextjs_site_domain_verified' => false,
                ]);

                DB::table('storefront_settings')->insert($nextjs);
            });
    }
};
