<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investments', function (Blueprint $table): void {
            if (! Schema::hasColumn('investments', 'status')) {
                $table->string('status', 20)->default('active')->after('amount')->index();
            }

            if (! Schema::hasColumn('investments', 'withdrawn_at')) {
                $table->date('withdrawn_at')->nullable()->after('status');
            }

            if (! Schema::hasColumn('investments', 'withdrawal_voucher_id')) {
                $table->foreignId('withdrawal_voucher_id')->nullable()->after('withdrawn_at')->constrained('vouchers')->nullOnDelete();
            }

            if (! Schema::hasColumn('investments', 'withdrawal_note')) {
                $table->text('withdrawal_note')->nullable()->after('withdrawal_voucher_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('investments', function (Blueprint $table): void {
            if (Schema::hasColumn('investments', 'withdrawal_voucher_id')) {
                $table->dropConstrainedForeignId('withdrawal_voucher_id');
            }

            foreach (['withdrawal_note', 'withdrawn_at', 'status'] as $column) {
                if (Schema::hasColumn('investments', $column)) {
                    if ($column === 'status') {
                        $table->dropIndex(['status']);
                    }
                    $table->dropColumn($column);
                }
            }
        });
    }
};
