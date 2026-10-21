<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchases', 'pl_number')) {
                $table->string('pl_number')->nullable()->after('ci_date');
            }
            if (! Schema::hasColumn('purchases', 'pl_date')) {
                $table->date('pl_date')->nullable()->after('pl_number');
            }
            if (! Schema::hasColumn('purchases', 'delivery_terms')) {
                $table->string('delivery_terms')->nullable()->after('pl_date');
            }
            if (! Schema::hasColumn('purchases', 'country_of_origin')) {
                $table->string('country_of_origin')->nullable()->after('delivery_terms');
            }
            if (! Schema::hasColumn('purchases', 'port_of_loading')) {
                $table->string('port_of_loading')->nullable()->after('country_of_origin');
            }
            if (! Schema::hasColumn('purchases', 'port_of_discharge')) {
                $table->string('port_of_discharge')->nullable()->after('port_of_loading');
            }
            if (! Schema::hasColumn('purchases', 'payment_method_summary')) {
                $table->string('payment_method_summary')->nullable()->after('port_of_discharge');
            }
            if (! Schema::hasColumn('purchases', 'terms_conditions')) {
                $table->text('terms_conditions')->nullable()->after('payment_method_summary');
            }
            if (! Schema::hasColumn('purchases', 'pl_certification_note')) {
                $table->text('pl_certification_note')->nullable()->after('terms_conditions');
            }
            if (! Schema::hasColumn('purchases', 'freight_usd')) {
                $table->decimal('freight_usd', 14, 2)->nullable()->after('pl_certification_note');
            }
            if (! Schema::hasColumn('purchases', 'exchange_rate')) {
                $table->decimal('exchange_rate', 10, 4)->nullable()->after('freight_usd');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table): void {
            foreach ([
                'pl_number', 'pl_date', 'delivery_terms', 'country_of_origin', 'port_of_loading',
                'port_of_discharge', 'payment_method_summary', 'terms_conditions', 'pl_certification_note',
                'freight_usd', 'exchange_rate',
            ] as $column) {
                if (Schema::hasColumn('purchases', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
