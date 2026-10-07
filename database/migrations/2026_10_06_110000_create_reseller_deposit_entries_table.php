<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A reseller's deposit (owner rule, 2026-10-06): money they sent above
        // an own-courier order's total is credited here (positive amount) and
        // used up on their next own-courier orders (negative amount). The
        // balance is the sum; it cannot be withdrawn.
        Schema::create('reseller_deposit_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('storefront_payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->decimal('amount', 14, 2);
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_deposit_entries');
    }
};
