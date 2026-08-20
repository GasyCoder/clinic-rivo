<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * ADR-005 / CDC §5: `uuid` is the distributed identifier for `users`,
     * one of the CDC's listed API-exchangeable resources (§6, `/api/v1/users`).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });

        // Backfill existing rows — new ones get one automatically via the
        // User model's HasUuid trait. saveQuietly() skips model events:
        // this is a one-off data fix, not a real "update" business event.
        User::query()->whereNull('uuid')->each(
            fn (User $user) => $user->forceFill(['uuid' => (string) Str::uuid()])->saveQuietly()
        );
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
