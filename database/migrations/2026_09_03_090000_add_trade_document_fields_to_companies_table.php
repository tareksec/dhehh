<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            if (! Schema::hasColumn('companies', 'bin_number')) {
                $table->string('bin_number')->nullable()->after('invoice_prefix');
            }
            if (! Schema::hasColumn('companies', 'irc_number')) {
                $table->string('irc_number')->nullable()->after('bin_number');
            }
            if (! Schema::hasColumn('companies', 'tin_number')) {
                $table->string('tin_number')->nullable()->after('irc_number');
            }
            if (! Schema::hasColumn('companies', 'signatory_name')) {
                $table->string('signatory_name')->nullable()->after('irc_number');
            }
            if (! Schema::hasColumn('companies', 'signatory_title')) {
                $table->string('signatory_title')->nullable()->after('signatory_name');
            }
            if (! Schema::hasColumn('companies', 'signature_path')) {
                $table->string('signature_path')->nullable()->after('signatory_title');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            foreach (['bin_number', 'irc_number', 'tin_number', 'signatory_name', 'signatory_title', 'signature_path'] as $column) {
                if (Schema::hasColumn('companies', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
