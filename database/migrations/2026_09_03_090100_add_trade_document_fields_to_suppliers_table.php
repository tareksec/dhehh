<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            if (! Schema::hasColumn('suppliers', 'country')) {
                $table->string('country')->nullable()->after('address');
            }
            if (! Schema::hasColumn('suppliers', 'fax')) {
                $table->string('fax')->nullable()->after('country');
            }
            if (! Schema::hasColumn('suppliers', 'bank_name')) {
                $table->string('bank_name')->nullable()->after('fax');
            }
            if (! Schema::hasColumn('suppliers', 'bank_address')) {
                $table->text('bank_address')->nullable()->after('bank_name');
            }
            if (! Schema::hasColumn('suppliers', 'bank_account_name')) {
                $table->string('bank_account_name')->nullable()->after('bank_address');
            }
            if (! Schema::hasColumn('suppliers', 'bank_account_number')) {
                $table->string('bank_account_number')->nullable()->after('bank_account_name');
            }
            if (! Schema::hasColumn('suppliers', 'bank_swift_code')) {
                $table->string('bank_swift_code')->nullable()->after('bank_account_number');
            }
            if (! Schema::hasColumn('suppliers', 'bank_extra_note')) {
                $table->string('bank_extra_note')->nullable()->after('bank_swift_code');
            }
            if (! Schema::hasColumn('suppliers', 'signature_path')) {
                $table->string('signature_path')->nullable()->after('bank_extra_note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            foreach ([
                'country', 'fax', 'bank_name', 'bank_address', 'bank_account_name',
                'bank_account_number', 'bank_swift_code', 'bank_extra_note', 'signature_path',
            ] as $column) {
                if (Schema::hasColumn('suppliers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
