<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ADR-146 — les dossiers patients ouverts à l'accouchement retournent à la fiche de leur mère.
 *
 * L'ADR-144 créait le patient dès l'accouchement ; l'ADR-146 a corrigé cet ordre — le bébé vit dans le
 * dossier de sa mère et ne devient patient qu'à l'accueil. Les dossiers déjà ouverts par l'ancien geste
 * restaient pourtant dans le répertoire, pour un enfant dont personne n'avait encore eu besoin.
 *
 * Ils y retournent : le nom est écrit dans la fiche — sans quoi il serait perdu, l'ancien geste ne
 * l'enregistrait que sur le patient —, puis le dossier patient est retiré et son numéro redevient
 * libre, pour que le bébé reçoive bien `-B1` le jour où la Réception l'accueille.
 *
 * **Uniquement un dossier qui n'a jamais servi.** Un bébé qui a déjà un passage, une facture, une
 * allergie ou la moindre ligne à son nom reste patient : on ne détruit pas un historique (ADR-010).
 * Le raisonnement est celui de l'ADR-062 pour un compte jamais utilisé, et la vérification porte sur
 * chaque table qui référence `patients` — la base refuse de toute façon (`RESTRICT`), mais un refus
 * brut au milieu d'une migration ne dirait pas lequel.
 */
return new class extends Migration
{
    /**
     * Les tables qui désignent un patient. Toutes en `RESTRICT` sauf `visitor_visits`, dont le
     * `SET NULL` effacerait le lien en silence : elle est donc vérifiée comme les autres.
     *
     * @var array<string, string>
     */
    private const REFERENCES = [
        'episodes' => 'patient_id',
        'invoices' => 'patient_id',
        'death_records' => 'patient_id',
        'patient_allergies' => 'patient_id',
        'patient_antecedents' => 'patient_id',
        'patient_debts' => 'patient_id',
        'patient_mutual_coverages' => 'patient_id',
        'patient_staff_links' => 'patient_id',
        'patient_treatments' => 'patient_id',
        'pharmacy_dispenses' => 'patient_id',
        'visitor_visits' => 'patient_id',
        'patient_newborn_links' => 'mother_patient_id',
    ];

    public function up(): void
    {
        $links = DB::table('patient_newborn_links as l')
            ->join('patients as p', 'p.id', '=', 'l.patient_id')
            ->join('maternity_records as m', 'm.id', '=', 'l.maternity_record_id')
            ->whereNull('p.deleted_at')
            ->select([
                'l.id as link_id', 'l.newborn_uuid', 'l.maternity_record_id',
                'p.id as patient_id', 'p.uuid as patient_uuid', 'p.patient_number',
                'p.first_name', 'p.last_name', 'm.newborn_data',
            ])
            ->get();

        foreach ($links as $link) {
            if ($this->hasServed($link->patient_id)) {
                continue;
            }

            DB::transaction(function () use ($link): void {
                $this->writeNameOnFiche($link);

                DB::table('patient_newborn_links')->where('id', $link->link_id)->delete();
                DB::table('patients')->where('id', $link->patient_id)->delete();

                // La création est déjà tracée (`maternity.newborn.patient.create`) : sans cette
                // seconde ligne, l'audit dirait qu'un dossier a été ouvert et jamais ce qu'il est
                // devenu.
                DB::table('audit_logs')->insert([
                    'uuid' => (string) Str::uuid(),
                    'action' => 'maternity.newborn.patient.revert',
                    'module' => 'maternity',
                    'entity_type' => 'App\\Models\\Patient',
                    'entity_id' => $link->patient_id,
                    'entity_uuid' => $link->patient_uuid,
                    'old_values' => json_encode([
                        'patient_number' => $link->patient_number,
                        'first_name' => $link->first_name,
                        'last_name' => $link->last_name,
                    ], JSON_UNESCAPED_UNICODE),
                    'reason' => 'Dossier ouvert à l’accouchement et jamais utilisé : le nouveau-né retourne à la fiche du dossier Maternité de sa mère (ADR-146).',
                    'created_at' => now(),
                ]);
            });
        }
    }

    public function down(): void
    {
        // Rouvrir ces dossiers reviendrait à recréer des patients que personne n'a demandés, avec des
        // numéros qu'un autre bébé a pu recevoir depuis. Le bébé redevient patient par l'accueil.
    }

    private function hasServed(int $patientId): bool
    {
        foreach (self::REFERENCES as $table => $column) {
            if (DB::table($table)->where($column, $patientId)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** Le nom saisi à la création vit sur le patient : il rejoint la fiche, sinon il disparaîtrait avec lui. */
    private function writeNameOnFiche(object $link): void
    {
        $data = json_decode((string) $link->newborn_data, true);

        if (! is_array($data) || ! is_array($data['newborns'] ?? null)) {
            return;
        }

        foreach ($data['newborns'] as $index => $newborn) {
            if (! is_array($newborn) || ($newborn['uuid'] ?? null) !== $link->newborn_uuid) {
                continue;
            }

            // Une fiche qui porte déjà un nom l'a reçu de la sage-femme : il fait foi.
            $data['newborns'][$index]['first_name'] = filled($newborn['first_name'] ?? null)
                ? $newborn['first_name']
                : $link->first_name;
            $data['newborns'][$index]['last_name'] = filled($newborn['last_name'] ?? null)
                ? $newborn['last_name']
                : $link->last_name;

            DB::table('maternity_records')
                ->where('id', $link->maternity_record_id)
                ->update(['newborn_data' => json_encode($data, JSON_UNESCAPED_UNICODE)]);

            return;
        }
    }
};
