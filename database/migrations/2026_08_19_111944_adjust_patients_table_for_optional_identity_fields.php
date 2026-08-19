<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reception feedback: first_name isn't always known/given, and some
     * patients only know their age, not their exact birth_date — both
     * become nullable. When only an age is given, RegisterArrivalAction
     * computes an approximate birth_date and sets
     * birth_date_is_approximate so that's never silently presented as
     * exact (matters for anything clinical that reads it later).
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('first_name')->nullable()->change();
            $table->date('birth_date')->nullable()->change();
            $table->boolean('birth_date_is_approximate')->default(false)->after('birth_date');
            $table->string('civility')->nullable()->after('sex');
            $table->string('email')->nullable()->after('phone');
            $table->string('emergency_contact_email')->nullable()->after('emergency_contact_relationship');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn(['birth_date_is_approximate', 'civility', 'email', 'emergency_contact_email']);
            $table->string('first_name')->nullable(false)->change();
            $table->date('birth_date')->nullable(false)->change();
        });
    }
};
