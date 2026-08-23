<?php

use App\Enums\EpisodeStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-034: "Personne à contacter" moves from the permanent patient
     * record to the episode — a different person can be reachable at each
     * passage, so Réception must be able to confirm or correct it at every
     * arrival, not only once when the dossier was created.
     *
     * Only currently OPEN episodes inherit the patient's last known contact
     * as a starting default: backfilling closed/historical episodes would
     * fabricate an association that was never actually confirmed for that
     * specific passage.
     */
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->string('emergency_contact_name')->nullable()->after('started_at');
            $table->string('emergency_contact_phone')->nullable()->after('emergency_contact_name');
            $table->string('emergency_contact_relationship')->nullable()->after('emergency_contact_phone');
            $table->string('emergency_contact_email')->nullable()->after('emergency_contact_relationship');
        });

        // Plain per-row updates rather than an UPDATE...JOIN: portable across
        // MySQL (production) and SQLite (tests), and this only ever touches
        // the small set of currently-open episodes.
        DB::table('episodes')
            ->join('patients', 'patients.id', '=', 'episodes.patient_id')
            ->where('episodes.status', EpisodeStatus::Open->value)
            ->whereNotNull('patients.emergency_contact_name')
            ->select(
                'episodes.id',
                'patients.emergency_contact_name',
                'patients.emergency_contact_phone',
                'patients.emergency_contact_relationship',
                'patients.emergency_contact_email',
            )
            ->get()
            ->each(fn ($row) => DB::table('episodes')->where('id', $row->id)->update([
                'emergency_contact_name' => $row->emergency_contact_name,
                'emergency_contact_phone' => $row->emergency_contact_phone,
                'emergency_contact_relationship' => $row->emergency_contact_relationship,
                'emergency_contact_email' => $row->emergency_contact_email,
            ]));

        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn([
                'emergency_contact_name', 'emergency_contact_phone',
                'emergency_contact_relationship', 'emergency_contact_email',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('emergency_contact_name')->nullable()->after('address');
            $table->string('emergency_contact_phone')->nullable()->after('emergency_contact_name');
            $table->string('emergency_contact_relationship')->nullable()->after('emergency_contact_phone');
            $table->string('emergency_contact_email')->nullable()->after('emergency_contact_relationship');
        });

        Schema::table('episodes', function (Blueprint $table) {
            $table->dropColumn([
                'emergency_contact_name', 'emergency_contact_phone',
                'emergency_contact_relationship', 'emergency_contact_email',
            ]);
        });
    }
};
