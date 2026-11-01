<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delete = Trash (owner request 2026-11-01, docs/delete-and-trash-plan.md, phase T3):
 * categories, offers / collections, product carousels, product reviews, hero
 * slides, custom pages, checkout payment methods, FAQs and quick replies are
 * kept when deleted and can be restored or deleted permanently from the
 * resource's Trash page.
 *
 * Their unique indexes (category / offer / page slug per company, payment
 * method name per company, one review per customer + product + order) are left
 * as they are on purpose: a row in Trash still holds its slug / name, so a
 * restore can never collide with something created in the meantime.
 */
return new class extends Migration
{
    private const TABLES = [
        'categories',
        'offers',
        'product_carousels',
        'product_reviews',
        'storefront_slides',
        'storefront_pages',
        'storefront_payment_methods',
        'company_faqs',
        'company_quick_replies',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropSoftDeletes();
            });
        }
    }
};
