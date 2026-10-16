<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = [
        'reseller_message_approved',
        'reseller_message_profit_payable',
        'reseller_message_payout_paid',
    ];

    public function up(): void
    {
        // Owner request (2026-10-16): SMS to a reseller when they are approved, when a
        // profit becomes payable and when a withdrawal is paid. The wording is the
        // owner's and starts empty; an empty message is never sent.
        Schema::table('storefront_settings', function (Blueprint $table): void {
            $after = 'reseller_announcement';

            foreach (self::COLUMNS as $column) {
                if (! Schema::hasColumn('storefront_settings', $column)) {
                    $table->text($column)->nullable()->after($after);
                }

                $after = $column;
            }
        });
    }

    public function down(): void
    {
        Schema::table('storefront_settings', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                if (Schema::hasColumn('storefront_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
