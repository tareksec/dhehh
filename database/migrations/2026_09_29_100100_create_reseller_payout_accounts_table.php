<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_payout_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // Same method keys as reseller_payout_requests.method (bkash, nagad, rocket, bank).
            $table->string('method', 20);
            $table->string('account_details', 500);
            $table->string('label', 100)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_payout_accounts');
    }
};
