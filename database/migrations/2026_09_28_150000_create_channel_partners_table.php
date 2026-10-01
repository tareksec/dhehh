<?php

use App\Support\InvestorIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner (2026-09-28): channel partners are not investors. They get their own
 * table and their own accounts. Each investor still names the partner who
 * brought them, and a project can have several partners.
 *
 * Every investor that acted as a channel partner (flagged, referenced by
 * another investor, or paid a partner payout) is copied into
 * `channel_partners`, and all links are pointed at the copy. The investor row
 * itself is left alone: it may have real investments.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('channel_partners')) {
            Schema::create('channel_partners', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('phone')->nullable();
                $table->text('address')->nullable();
                $table->text('nid_number')->nullable();
                $table->string('nid_hash', 64)->nullable();
                $table->string('payment_method', 20)->nullable();
                $table->string('account_name')->nullable();
                $table->string('account_number')->nullable();
                $table->string('bank_name')->nullable();
                $table->string('bank_branch')->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('legacy_investor_id')->nullable();
                $table->timestamps();
                $table->index(['company_id', 'nid_hash']);
                $table->index(['company_id', 'name']);
            });
        }

        $partnerIds = $this->copyPartnerInvestors();

        $this->repointForeignKey('investors', $partnerIds);
        $this->repointForeignKey('investor_drafts', $partnerIds);
        $this->movePayouts($partnerIds);

        foreach (['investors', 'investor_drafts'] as $table) {
            if (Schema::hasColumn($table, 'is_channel_partner')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('is_channel_partner'));
            }
        }
    }

    public function down(): void
    {
        // The partner data now lives only in channel_partners; this migration
        // is not reversible without losing it.
        throw new RuntimeException('Channel partners were separated from investors; this migration cannot be rolled back.');
    }

    /** @return array<int, int> old investor id => new channel partner id */
    private function copyPartnerInvestors(): array
    {
        $ids = collect()
            ->merge(Schema::hasColumn('investors', 'is_channel_partner') ? DB::table('investors')->where('is_channel_partner', true)->pluck('id') : [])
            ->merge(DB::table('investors')->whereNotNull('channel_partner_id')->pluck('channel_partner_id'))
            ->merge(Schema::hasColumn('investor_drafts', 'channel_partner_id') ? DB::table('investor_drafts')->whereNotNull('channel_partner_id')->pluck('channel_partner_id') : [])
            ->merge(Schema::hasColumn('channel_partner_payouts', 'investor_id') ? DB::table('channel_partner_payouts')->pluck('investor_id') : [])
            ->filter()->unique()->values();

        $map = DB::table('channel_partners')->whereNotNull('legacy_investor_id')->pluck('id', 'legacy_investor_id')->all();

        foreach (DB::table('investors')->whereIn('id', $ids)->orderBy('id')->get() as $investor) {
            if (isset($map[$investor->id])) {
                continue;
            }

            $nidHash = $investor->nid_hash ?? null;

            if (! $nidHash && filled($investor->nid_number)) {
                try {
                    $nidHash = InvestorIdentity::hash(Crypt::decryptString($investor->nid_number));
                } catch (Throwable) {
                    $nidHash = InvestorIdentity::hash($investor->nid_number);
                }
            }

            $map[$investor->id] = DB::table('channel_partners')->insertGetId([
                'company_id' => $investor->company_id,
                'name' => $investor->name,
                'phone' => $investor->phone,
                'address' => $investor->address,
                'nid_number' => $investor->nid_number,
                'nid_hash' => $nidHash,
                'is_active' => true,
                'legacy_investor_id' => $investor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $map;
    }

    /** @param  array<int, int>  $partnerIds */
    private function repointForeignKey(string $table, array $partnerIds): void
    {
        if (! Schema::hasColumn($table, 'channel_partner_id')) {
            return;
        }

        $links = DB::table($table)->whereNotNull('channel_partner_id')->pluck('channel_partner_id', 'id');

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropForeign(['channel_partner_id']);
        });

        DB::table($table)->update(['channel_partner_id' => null]);

        foreach ($links as $rowId => $investorId) {
            DB::table($table)->where('id', $rowId)->update(['channel_partner_id' => $partnerIds[$investorId] ?? null]);
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->foreign('channel_partner_id')->references('id')->on('channel_partners')->nullOnDelete();
        });
    }

    /** @param  array<int, int>  $partnerIds */
    private function movePayouts(array $partnerIds): void
    {
        if (! Schema::hasColumn('channel_partner_payouts', 'investor_id')) {
            return;
        }

        if (! Schema::hasColumn('channel_partner_payouts', 'channel_partner_id')) {
            Schema::table('channel_partner_payouts', function (Blueprint $table): void {
                $table->foreignId('channel_partner_id')->nullable()->after('settlement_id')->constrained('channel_partners')->cascadeOnDelete();
            });
        }

        foreach (DB::table('channel_partner_payouts')->get(['id', 'investor_id']) as $payout) {
            DB::table('channel_partner_payouts')->where('id', $payout->id)->update(['channel_partner_id' => $partnerIds[$payout->investor_id] ?? null]);
        }

        // Several partners per project: one payout per partner per settlement.
        $indexes = collect(Schema::getIndexes('channel_partner_payouts'));

        if (! $indexes->contains(fn (array $index): bool => $index['columns'] === ['settlement_id', 'channel_partner_id'])) {
            Schema::table('channel_partner_payouts', function (Blueprint $table): void {
                $table->unique(['settlement_id', 'channel_partner_id']);
            });
        }

        // The old one-payout-per-settlement unique index (its name differs by driver).
        foreach (Schema::getIndexes('channel_partner_payouts') as $index) {
            if ($index['unique'] && ! $index['primary'] && $index['columns'] === ['settlement_id']) {
                Schema::table('channel_partner_payouts', fn (Blueprint $table) => $table->dropIndex($index['name']));
            }
        }

        Schema::table('channel_partner_payouts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('investor_id');
        });
    }
};
