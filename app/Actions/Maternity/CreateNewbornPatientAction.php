<?php

namespace App\Actions\Maternity;

use App\Models\MaternityRecord;
use App\Models\Patient;
use App\Models\PatientNewbornLink;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Patient\PatientNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-144, ADR-146, ADR-177 — fait d'un nouveau-né consigné en Maternité un patient relié à sa mère.
 *
 * Le bébé vit d'abord dans le dossier de sa mère (ADR-146) : il n'est **pas** créé patient à l'accouchement.
 * Il le devient par un geste explicite **depuis la Maternité**, là où il est consigné (ADR-177) — la
 * Réception n'a plus de mode « Nouveau-né » ; un bébé né ailleurs y est un nouveau patient ordinaire.
 *
 * ```text
 * numéro    dérivé de celui de la mère — A-26-0009-B1 (rang de naissance)
 * naissance date de l'accouchement consigné : jamais devinée, jamais « aujourd'hui »
 * sexe      M ou F, exigé : le dossier patient n'a pas d'état « indéterminé »
 * nom       celui de la fiche ; à défaut celui de sa mère — le bébé n'est pas toujours prénommé
 * ```
 *
 * Elle ne passe **pas** par `CreatePatientAction` : la détection de doublons y bloquerait des jumeaux — même
 * nom, même date de naissance. L'unicité (dossier Maternité, bébé) est ici la garde contre le doublon, et elle
 * est exacte : un double clic retrouve le patient déjà créé.
 *
 * Elle ne dépend d'aucun passage : le dossier peut s'ouvrir des jours après, quand celui de sa mère est clos, et
 * elle n'y touche pas. Aucun passage n'est ouvert non plus — la Réception en ouvrira un, comme pour tout patient
 * existant, le jour où le bébé reviendra. Les soins du bébé consignés en Maternité restent sur le compte de la
 * mère (choix du propriétaire).
 */
class CreateNewbornPatientAction
{
    public function __construct(
        private readonly PatientNumberGenerator $numbers,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{last_name?: ?string, first_name?: ?string, sex?: ?string}  $identity  ce que la Maternité confirme ou complète
     */
    public function execute(MaternityRecord $record, string $newbornUuid, array $identity, User $actor): PatientNewbornLink
    {
        return DB::transaction(function () use ($record, $newbornUuid, $identity, $actor): PatientNewbornLink {
            $locked = MaternityRecord::query()->with('episode.patient')->lockForUpdate()->findOrFail($record->getKey());
            $mother = $locked->episode?->patient;

            if ($mother === null) {
                throw ValidationException::withMessages(['newborn' => 'Ce dossier Maternité n’est rattaché à aucune mère.']);
            }

            $newborns = $locked->newborn_data['newborns'] ?? [];
            $index = collect($newborns)->search(fn ($entry) => is_array($entry) && ($entry['uuid'] ?? null) === $newbornUuid);

            if ($index === false) {
                throw ValidationException::withMessages(['newborn' => 'Ce nouveau-né n’existe pas dans le dossier Maternité de sa mère.']);
            }

            $newborn = $newborns[$index];

            $existing = PatientNewbornLink::query()
                ->where('maternity_record_id', $locked->getKey())
                ->where('newborn_uuid', $newbornUuid)
                ->first();

            // Un double clic retrouve le dossier déjà créé : jamais un second patient pour le même enfant.
            if ($existing !== null) {
                return $existing->load('patient');
            }

            $birthAt = $locked->delivery_data['occurred_at'] ?? null;

            if (blank($birthAt)) {
                throw ValidationException::withMessages(['newborn' => 'La date et l’heure de l’accouchement ne sont pas consignées à la Maternité : la naissance du bébé n’est jamais devinée.']);
            }

            // Le sexe de la fiche fait foi. Quand elle ne le porte pas, la Maternité le donne — et il est alors
            // écrit sur la fiche, pour que le dossier du bébé et celui de sa mère ne se contredisent jamais.
            $sex = in_array($newborn['sex'] ?? null, ['M', 'F'], true) ? $newborn['sex'] : ($identity['sex'] ?? null);

            if (! in_array($sex, ['M', 'F'], true)) {
                throw ValidationException::withMessages(['sex' => 'Choisissez le sexe du bébé (Masculin ou Féminin) : un dossier patient ne peut pas encore porter un sexe indéterminé.']);
            }

            if (($newborn['sex'] ?? null) !== $sex) {
                $data = $locked->newborn_data;
                $data['newborns'][$index]['sex'] = $sex;
                $locked->fill(['newborn_data' => $data, 'updated_by' => $actor->getKey()])->save();
            }

            $lastName = Str::squish((string) ($identity['last_name'] ?? ''))
                ?: Str::squish((string) ($newborn['last_name'] ?? ''))
                ?: Str::squish((string) $mother->last_name);
            $firstName = Str::squish((string) ($identity['first_name'] ?? '')) ?: Str::squish((string) ($newborn['first_name'] ?? ''));

            $patient = Patient::create([
                'patient_number' => $this->numbers->newborn($mother, $index + 1),
                'first_name' => $firstName ?: null,
                'last_name' => $lastName,
                'birth_date' => CarbonImmutable::parse($birthAt)->toDateString(),
                'birth_date_is_approximate' => false,
                'sex' => $sex,
            ]);

            $link = PatientNewbornLink::query()->create([
                'patient_id' => $patient->getKey(),
                'mother_patient_id' => $mother->getKey(),
                'maternity_record_id' => $locked->getKey(),
                'newborn_uuid' => $newbornUuid,
                'birth_rank' => $index + 1,
                'created_by' => $actor->getKey(),
            ]);

            $this->auditor->record(
                'maternity.newborn.patient.create',
                entity: $patient,
                newValues: [
                    'patient_number' => $patient->patient_number,
                    'mother_patient_number' => $mother->patient_number,
                    'birth_rank' => $index + 1,
                ],
                module: 'maternity',
                actor: $actor,
            );

            return $link->load('patient');
        });
    }
}
