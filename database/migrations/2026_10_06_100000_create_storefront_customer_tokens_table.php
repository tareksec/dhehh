<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A logged-in customer of the headless Next.js storefront. The website
        // keeps the plain token in an httpOnly cookie on ITS server and sends
        // it to the ERP next to the company API key; only the SHA-256 hash is
        // stored here, the same way company_api_keys works.
        Schema::create('storefront_customer_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['customer_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_customer_tokens');
    }
};
