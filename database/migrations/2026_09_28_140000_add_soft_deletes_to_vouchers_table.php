<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            if (! Schema::hasColumn('vouchers', 'deleted_at')) {
                $table->softDeletes()->index();
            }

            if (! Schema::hasColumn('vouchers', 'deleted_by')) {
                $table->foreignId('deleted_by')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            if (Schema::hasColumn('vouchers', 'deleted_by')) {
                $table->dropConstrainedForeignId('deleted_by');
            }

            if (Schema::hasColumn('vouchers', 'deleted_at')) {
                $table->dropIndex(['deleted_at']);
                $table->dropSoftDeletes();
            }
        });
    }
};
