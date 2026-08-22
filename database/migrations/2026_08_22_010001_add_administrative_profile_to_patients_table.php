<?php

use App\Enums\PatientType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('patient_type', 20)
                ->default(PatientType::Standard->value)
                ->after('patient_number')
                ->index();
            $table->string('marital_status', 20)->nullable()->after('identity_document_number');
            $table->unsignedSmallInteger('children_count')->nullable()->after('marital_status');
            $table->string('profession')->nullable()->after('children_count');
            $table->unsignedTinyInteger('declared_age')->nullable()->after('birth_date_is_approximate');
            $table->timestamp('declared_age_at')->nullable()->after('declared_age');
            $table->foreignId('address_entry_id')
                ->nullable()
                ->after('address')
                ->constrained('address_entries')
                ->restrictOnDelete();
        });

        // Earlier versions manufactured `today - age` as a birth date when
        // the family only declared an age. Recover the original declaration
        // relative to the record creation date, then remove that false date.
        DB::table('patients')
            ->where('birth_date_is_approximate', true)
            ->whereNotNull('birth_date')
            ->orderBy('id')
            ->chunkById(500, function ($patients): void {
                foreach ($patients as $patient) {
                    $declaredAt = $patient->created_at
                        ? CarbonImmutable::parse($patient->created_at)
                        : now()->toImmutable();
                    $birthDate = CarbonImmutable::parse($patient->birth_date);
                    $declaredAge = max(0, min(255, (int) floor($birthDate->diffInYears($declaredAt))));

                    DB::table('patients')->where('id', $patient->id)->update([
                        'birth_date' => null,
                        'declared_age' => $declaredAge,
                        'declared_age_at' => $declaredAt,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Preserve the old application's expected representation if this
        // migration is rolled back: recreate only the explicitly approximate
        // date, never touch exact dates.
        DB::table('patients')
            ->where('birth_date_is_approximate', true)
            ->whereNull('birth_date')
            ->whereNotNull('declared_age')
            ->orderBy('id')
            ->chunkById(500, function ($patients): void {
                foreach ($patients as $patient) {
                    $declaredAt = $patient->declared_age_at
                        ? CarbonImmutable::parse($patient->declared_age_at)
                        : ($patient->created_at
                            ? CarbonImmutable::parse($patient->created_at)
                            : now()->toImmutable());

                    DB::table('patients')->where('id', $patient->id)->update([
                        'birth_date' => $declaredAt
                            ->startOfDay()
                            ->subYears((int) $patient->declared_age)
                            ->toDateString(),
                    ]);
                }
            });

        Schema::table('patients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('address_entry_id');
            $table->dropColumn([
                'patient_type',
                'marital_status',
                'children_count',
                'profession',
                'declared_age',
                'declared_age_at',
            ]);
        });
    }
};
