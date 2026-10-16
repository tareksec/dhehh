<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Owner decisions (2026-10-16): reseller levels are named and counted by the admin, a
        // reseller's level is set by hand, and a level may carry a lower return charge and a
        // lower minimum withdrawal than the company's. Nothing about money changes on its own.
        if (! Schema::hasTable('reseller_levels')) {
            Schema::create('reseller_levels', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('name', 80);
                $table->unsignedInteger('sort_order')->default(0);
                // Empty = the company's own amount from Reseller Settings applies.
                $table->decimal('return_charge', 12, 2)->nullable();
                $table->decimal('min_withdrawal', 12, 2)->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'name'], 'reseller_levels_company_name_unique');
            });
        }

        if (! Schema::hasColumn('customers', 'reseller_level_id')) {
            Schema::table('customers', function (Blueprint $table): void {
                // Deleting a level puts its resellers back on the company's amounts.
                $table->foreignId('reseller_level_id')->nullable()->constrained('reseller_levels')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'reseller_level_id')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('reseller_level_id');
            });
        }

        Schema::dropIfExists('reseller_levels');
    }
};
