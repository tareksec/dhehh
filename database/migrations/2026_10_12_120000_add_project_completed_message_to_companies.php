<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The congratulation SMS sent to every investor of a group project when its
 * two months are over (16_INVESTOR_GROUPS_PLAN.md §3.8). The wording is the
 * owner's, so it starts empty: nothing is sent until they write it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->text('investor_project_completed_message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('investor_project_completed_message');
        });
    }
};
