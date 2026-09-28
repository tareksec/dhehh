<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An imported investor draft can now carry the investment from the sheet
 * (project, amount, payment method, date), created together with the
 * investor on confirm, and can point at an investor who already exists
 * (matched by phone) so confirming only adds the new investment to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investor_drafts', function (Blueprint $table): void {
            if (! Schema::hasColumn('investor_drafts', 'existing_investor_id')) {
                $table->foreignId('existing_investor_id')->nullable()->after('source_row')->constrained('investors')->nullOnDelete();
            }

            if (! Schema::hasColumn('investor_drafts', 'project_id')) {
                $table->foreignId('project_id')->nullable()->after('nominee_address')->constrained('investment_projects')->nullOnDelete();
            }

            if (! Schema::hasColumn('investor_drafts', 'investment_amount')) {
                $table->decimal('investment_amount', 15, 2)->nullable()->after('project_id');
            }

            if (! Schema::hasColumn('investor_drafts', 'payment_method')) {
                $table->string('payment_method', 20)->nullable()->after('investment_amount');
            }

            if (! Schema::hasColumn('investor_drafts', 'invested_at')) {
                $table->date('invested_at')->nullable()->after('payment_method');
            }
        });
    }

    public function down(): void
    {
        Schema::table('investor_drafts', function (Blueprint $table): void {
            foreach (['existing_investor_id', 'project_id'] as $foreign) {
                if (Schema::hasColumn('investor_drafts', $foreign)) {
                    $table->dropConstrainedForeignId($foreign);
                }
            }

            foreach (['investment_amount', 'payment_method', 'invested_at'] as $column) {
                if (Schema::hasColumn('investor_drafts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
