<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AI inbox control plane, phase 7 (owner answer O9, 2026-10-19): the
        // "To chase" list is worked out from carts, unpaid checkouts and
        // leads; the only thing stored is who a person already called or
        // wrote to, so the same customer is not chased twice.
        Schema::create('chase_marks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 20); // normalised, see ContactSuppressionService::normalize()
            $table->timestamp('contacted_at');
            $table->foreignId('contacted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'phone'], 'chase_marks_company_phone_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chase_marks');
    }
};
