<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            // A "collection" offer is a curated product list (Flash Sale,
            // Trending, New Arrivals) for the headless Next.js storefront —
            // it has no landing page or checkout of its own. `type` is already
            // a free string column, so only the collection-specific fields are new.
            $table->string('collection_kind', 24)->nullable()->after('type');
            $table->timestamp('starts_at')->nullable()->after('collection_kind');
            $table->timestamp('ends_at')->nullable()->after('starts_at');
        });

        Schema::table('offer_items', function (Blueprint $table): void {
            // Optional price shown for this product while a collection is live.
            $table->decimal('deal_price', 12, 2)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('offer_items', function (Blueprint $table): void {
            $table->dropColumn('deal_price');
        });

        Schema::table('offers', function (Blueprint $table): void {
            $table->dropColumn(['collection_kind', 'starts_at', 'ends_at']);
        });
    }
};
