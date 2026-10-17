<?php

use App\Support\InvestorIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Investors are identified by NID / passport number (owner's rule), which
 * is encrypted at rest. `nid_hash` is a keyed hash of the normalised number
 * so it can be searched and checked for duplicates. Existing rows are
 * backfilled here; uniqueness per company is enforced by the models (an
 * index, not a unique constraint, so a deploy never fails on old duplicates).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['investors', 'investor_drafts'] as $table) {
            if (! Schema::hasColumn($table, 'nid_hash')) {
                Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                    $blueprint->string('nid_hash', 64)->nullable()->after('nid_number');
                    $blueprint->index(['company_id', 'nid_hash'], "{$table}_company_nid_hash_index");
                });
            }

            DB::table($table)->whereNotNull('nid_number')->whereNull('nid_hash')->orderBy('id')->each(function (object $row) use ($table): void {
                try {
                    $number = Crypt::decryptString($row->nid_number);
                } catch (Throwable) {
                    $number = $row->nid_number;
                }

                DB::table($table)->where('id', $row->id)->update(['nid_hash' => InvestorIdentity::hash($number)]);
            });
        }
    }

    public function down(): void
    {
        foreach (['investors', 'investor_drafts'] as $table) {
            if (Schema::hasColumn($table, 'nid_hash')) {
                Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                    $blueprint->dropIndex("{$table}_company_nid_hash_index");
                    $blueprint->dropColumn('nid_hash');
                });
            }
        }
    }
};
