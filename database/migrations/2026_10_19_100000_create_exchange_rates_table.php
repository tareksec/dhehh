<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('exchange_rates')) {
            return;
        }

        // Owner (2026-10-19, 17_IMS_IN_PORTAL_PLAN.md): the investor portal becomes
        // the IMS, which keeps daily USD / RMB rates. Staff type them; nothing is
        // fetched from outside. `rate` is BDT for one unit of the currency.
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 3);
            $table->date('rate_date');
            $table->decimal('rate', 16, 6);
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'currency', 'rate_date'], 'exchange_rates_company_currency_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
