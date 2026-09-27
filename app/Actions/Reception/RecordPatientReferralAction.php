<?php

namespace App\Actions\Reception;

use App\Enums\ReferralSource;
use App\Models\Employee;
use App\Models\Episode;
use App\Models\PartnerOrganization;
use App\Models\Patient;
use App\Models\PatientReferral;
use App\Models\User;
use App\Support\Authorization\RemoteActorAttribution;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-212 — noter qui a recommandé la clinique à un nouveau patient.
 *
 * Appelée dans la transaction de l'arrivée, seulement quand le dossier patient
 * vient d'être créé : une recommandation se note à la première venue, jamais
 * après coup sur un dossier existant. Le recommandant est un membre du
 * personnel ou un partenaire (par sa fiche, retrouvé sur le serveur), ou une
 * autre personne (son nom). Son nom est gardé tel qu'il est ce jour-là.
 */
class RecordPatientReferralAction
{
    /**
     * @param  array{source: string, employee_uuid?: string|null, partner_uuid?: string|null, name?: string|null, phone?: string|null}  $referral
     */
    public function execute(Patient $patient, Episode $episode, array $referral, User $actor): PatientReferral
    {
        if ($actor->cannot('patient_referrals.create')) {
            throw new AuthorizationException('Vous ne pouvez pas noter qui a recommandé la clinique.');
        }

        if ($patient->referral()->exists()) {
            throw ValidationException::withMessages([
                'referral' => 'La recommandation de ce patient est déjà notée.',
            ]);
        }

        $source = ReferralSource::from($referral['source']);
        $attributes = [
            'patient_id' => $patient->getKey(),
            'episode_id' => $episode->getKey(),
            'source' => $source,
            'referred_at' => now(),
            'recorded_by' => $actor->getKey(),
            ...RemoteActorAttribution::fields('recorded', $actor),
        ];

        return PatientReferral::query()->create([...$attributes, ...match ($source) {
            ReferralSource::Employee => $this->employee((string) ($referral['employee_uuid'] ?? '')),
            ReferralSource::Partner => $this->partner((string) ($referral['partner_uuid'] ?? ''), $patient),
            ReferralSource::Other => $this->otherPerson($referral),
        }]);
    }

    /** @return array<string, mixed> */
    private function employee(string $uuid): array
    {
        $employee = Employee::query()->where('uuid', $uuid)->where('active', true)->first();

        if (! $employee) {
            throw ValidationException::withMessages(['referral.employee_uuid' => 'Ce membre du personnel n’est plus en poste.']);
        }

        return [
            'employee_id' => $employee->getKey(),
            'referrer_name' => Str::squish("{$employee->last_name} {$employee->first_name}"),
            'referrer_phone' => $employee->phone,
        ];
    }

    /** @return array<string, mixed> */
    private function partner(string $uuid, Patient $patient): array
    {
        $partner = PartnerOrganization::query()->where('uuid', $uuid)->where('active', true)->first();

        if (! $partner) {
            throw ValidationException::withMessages(['referral.partner_uuid' => 'Ce partenaire est indisponible ou archivé.']);
        }

        // Un partenaire médical venu se faire soigner ne se recommande pas à lui-même.
        if ($partner->patient_id === $patient->getKey()) {
            throw ValidationException::withMessages(['referral.partner_uuid' => 'Le patient ne peut pas s’être recommandé la clinique lui-même.']);
        }

        return [
            'partner_organization_id' => $partner->getKey(),
            'referrer_name' => $partner->name,
            'referrer_phone' => $partner->phone,
        ];
    }

    /** @return array<string, mixed> */
    private function otherPerson(array $referral): array
    {
        $name = Str::squish((string) ($referral['name'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages(['referral.name' => 'Indiquez le nom de la personne qui a recommandé la clinique.']);
        }

        $phone = Str::squish((string) ($referral['phone'] ?? ''));

        return ['referrer_name' => $name, 'referrer_phone' => $phone !== '' ? $phone : null];
    }
}
