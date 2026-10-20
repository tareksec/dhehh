<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A project cost can be entered in USD or RMB. `amount` stays the BDT figure
        // every report and the settlement already read; for a foreign cost it is
        // original_amount × exchange_rate, set by the model. Existing rows are BDT.
        Schema::table('project_cost_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('project_cost_items', 'currency')) {
                $table->string('currency', 3)->nullable()->after('amount');
            }

            if (! Schema::hasColumn('project_cost_items', 'original_amount')) {
                $table->decimal('original_amount', 16, 2)->nullable()->after('currency');
            }

            if (! Schema::hasColumn('project_cost_items', 'exchange_rate')) {
                $table->decimal('exchange_rate', 16, 6)->nullable()->after('original_amount');
            }

            if (! Schema::hasColumn('project_cost_items', 'cost_date')) {
                $table->date('cost_date')->nullable()->after('exchange_rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_cost_items', function (Blueprint $table): void {
            foreach (['cost_date', 'exchange_rate', 'original_amount', 'currency'] as $column) {
                if (Schema::hasColumn('project_cost_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
