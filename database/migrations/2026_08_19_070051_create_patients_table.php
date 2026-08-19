<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Schema fixed by the CDC (§21) — id/uuid/patient_number/first_name/
     * last_name/birth_date/sex/phone/address/timestamps/deleted_at.
     * deleted_by/delete_reason added on top per the general ADR-009 rule
     * (SoftDeletable), not itemized in §21's abbreviated listing but not
     * excluded by it either — every other soft-deletable table in this
     * codebase carries them the same way.
     */
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('patient_number')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->date('birth_date');
            $table->string('sex', 1);
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
            $table->softDeletesWithReason();

            $table->index(['last_name', 'first_name', 'birth_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
