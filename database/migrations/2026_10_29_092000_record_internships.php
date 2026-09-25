<?php

use App\Enums\HrReferenceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ADR-194 — un stagiaire est un employé dont le contrat est « de stage ».
 * Le stage porte sa filière (Infirmier, Sage-femme…), son école, son niveau
 * et son encadrant. Le type « Stagiaire » livré est marqué contrat de stage ;
 * les filières sont un référentiel configurable, proposé une fois.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const FIELDS = [
        'NURSING' => 'Infirmier',
        'MIDWIFERY' => 'Sage-femme',
        'MEDICINE' => 'Médecine',
        'ANESTHESIA' => 'Anesthésie',
        'LABORATORY' => 'Laboratoire',
        'PHARMACY' => 'Pharmacie',
        'ADMINISTRATION' => 'Administration',
    ];

    public function up(): void
    {
        Schema::table('employment_contracts', function (Blueprint $table): void {
            $table->foreignId('internship_field_id')->nullable()->after('contract_type_id')->constrained('hr_reference_values');
            $table->string('internship_school')->nullable()->after('internship_field_id');
            $table->string('internship_level', 100)->nullable()->after('internship_school');
            $table->foreignId('internship_supervisor_id')->nullable()->after('internship_level')->constrained('employees');
        });

        DB::table('hr_reference_values')
            ->where('type', HrReferenceType::ContractType->value)
            ->where('code', 'INTERN')
            ->get(['id', 'metadata'])
            ->each(function (object $type): void {
                $metadata = json_decode((string) $type->metadata, true) ?: [];

                if (! array_key_exists('internship', $metadata)) {
                    DB::table('hr_reference_values')->where('id', $type->id)
                        ->update(['metadata' => json_encode([...$metadata, 'internship' => true])]);
                }
            });

        if (! DB::table('hr_reference_values')->where('type', HrReferenceType::InternshipField->value)->exists()) {
            $position = 0;
            foreach (self::FIELDS as $code => $label) {
                DB::table('hr_reference_values')->insert([
                    'uuid' => (string) Str::uuid(),
                    'type' => HrReferenceType::InternshipField->value,
                    'code' => $code,
                    'label' => $label,
                    'active' => true,
                    'position' => $position++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('employment_contracts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('internship_supervisor_id');
            $table->dropColumn(['internship_school', 'internship_level']);
            $table->dropConstrainedForeignId('internship_field_id');
        });
    }
};
