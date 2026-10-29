<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Full product control of the company website from the ERP (owner request
 * 2026-10-29, Tasneem Knit).
 *
 * products.specifications: the product's technical specifications as an
 * ordered label => value list (gauge, dia, feeders ...), edited on the product
 * form and sent to the website with the rest of the product.
 *
 * website_webhook_deliveries: one row per outbound website webhook delivery
 * (event, SKU, HTTP status, attempts, error), shown on Settings → Integrations
 * → Website API so a failing sync is visible instead of only in the server log.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'specifications')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->json('specifications')->nullable();
            });
        }

        Schema::create('website_webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('event', 60);
            $table->string('sku', 190)->nullable();
            $table->string('url', 255);
            $table->string('status', 20);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->unsignedTinyInteger('attempts')->default(1);
            $table->text('response_excerpt')->nullable();
            $table->text('error')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at'], 'website_webhook_deliveries_company_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_webhook_deliveries');

        if (Schema::hasColumn('products', 'specifications')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('specifications');
            });
        }
    }
};
