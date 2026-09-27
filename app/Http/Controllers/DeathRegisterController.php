<?php

namespace App\Http\Controllers;

use App\Actions\Medicine\RecordDeathCertificateAction;
use App\Enums\MedicalDischargeType;
use App\Http\Requests\Medicine\StoreDeathCertificateRequest;
use App\Models\Episode;
use App\Services\Medicine\ClinicalRichTextSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Le registre des décès (ADR-107).
 *
 * Un décès prononcé par la Médecine (ADR-035) n'apparaissait ensuite nulle
 * part : la file Médecine ne montre que les prises en charge en cours, et le
 * passage partait en « Sorties & règlements » comme n'importe quel autre.
 * Personne n'avait donc d'écran pour retrouver les patients concernés ni
 * pour établir l'acte de constatation.
 *
 * Cet espace n'encaisse rien et ne prononce aucun décès : il liste ce que la
 * Médecine a déjà décidé, et permet d'en signer le document.
 */
class DeathRegisterController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $filter = in_array($request->query('filter'), ['pending', 'recorded'], true)
            ? $request->query('filter')
            : 'all';

        $base = Episode::query()
            ->whereHas('medicalDischarge', fn ($query) => $query
                ->where('type', MedicalDischargeType::Deceased->value));

        $counts = [
            'all' => (clone $base)->count(),
            // « À établir » est le travail réel de cet écran : un acte qui
            // manque est un document que la famille n'a pas.
            'pending' => (clone $base)->whereDoesntHave('deathRecord')->count(),
            'recorded' => (clone $base)->whereHas('deathRecord')->count(),
        ];

        $episodes = $base
            ->with([
                'patient:id,uuid,patient_number,first_name,last_name,birth_date,birth_date_is_approximate,declared_age,birth_place,sex,address,identity_document_number',
                'medicalDischarge',
                'deathRecord.constatedBy:id,name',
            ])
            ->when($filter === 'pending', fn ($query) => $query->whereDoesntHave('deathRecord'))
            ->when($filter === 'recorded', fn ($query) => $query->whereHas('deathRecord'))
            ->when($search !== '', fn ($query) => $query
                ->where(fn ($outer) => $outer
                    ->where('episode_number', 'like', "%{$search}%")
                    ->orWhereHas('patient', fn ($patient) => $patient
                        ->where('patient_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%"))))
            // Le plus récent d'abord : un acte se signe dans les heures qui
            // suivent, pas dans l'ordre d'arrivée du passage.
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $canRecord = (bool) $request->user()?->can('death_records.create');

        $episodes->through(fn (Episode $episode): array => [
            'uuid' => $episode->uuid,
            'episode_number' => $episode->episode_number,
            'patient' => [
                'uuid' => $episode->patient?->uuid,
                'patient_number' => $episode->patient?->patient_number,
                'name' => trim(($episode->patient?->first_name ?? '').' '.($episode->patient?->last_name ?? '')),
                'sex' => $episode->patient?->sex,
                'birth_date' => $episode->patient?->birth_date,
                'birth_date_is_approximate' => (bool) $episode->patient?->birth_date_is_approximate,
                // Un patient sans date de naissance connue peut n'avoir
                // qu'un âge déclaré (ADR-030) : la même règle que sur l'acte
                // imprimé (print()) et le dossier médical, pour ne jamais
                // afficher « non renseignée » quand le dossier sait un âge.
                'age' => $episode->patient?->birth_date?->age ?? $episode->patient?->declared_age,
                // Ce que le dossier sait déjà : l'acte le reprend, le médecin
                // le corrige s'il le faut, sans toucher au dossier.
                'birth_place' => $episode->patient?->birth_place,
                'address' => $episode->patient?->address,
                'identity_document_number' => $episode->patient?->identity_document_number,
            ],
            'death_occurred_at' => $episode->medicalDischarge?->death_occurred_at,
            'death_place' => $episode->medicalDischarge?->death_place,
            'death_causes' => $episode->medicalDischarge?->death_causes,
            'discharged_at' => $episode->medicalDischarge?->discharged_at,
            'record' => $episode->deathRecord ? [
                'uuid' => $episode->deathRecord->uuid,
                'constated_at' => $episode->deathRecord->constated_at,
                'constated_by' => $episode->deathRecord->constatedBy?->name,
            ] : null,
            'episode_url' => "/passages/{$episode->uuid}",
            // Le droit gouverne le bouton, et la route le revérifie : le
            // filtrage Vue ne sert que l'ergonomie.
            'can_record' => $canRecord && $episode->deathRecord === null,
        ]);

        return Inertia::render('Deaths/Index', [
            'episodes' => $episodes,
            'counts' => $counts,
            'filter' => $filter,
            'search' => $search,
            'capabilities' => ['can_record' => $canRecord],
            'defaultSignedPlace' => config('rivo.site.name'),
        ]);
    }

    public function store(
        StoreDeathCertificateRequest $request,
        Episode $episode,
        RecordDeathCertificateAction $action,
    ): RedirectResponse {
        $action->execute($episode, $request->validated(), $request->user());

        return redirect()->route('deaths.index')
            ->with('status', 'Acte de constatation de décès établi.');
    }

    public function print(Request $request, Episode $episode, ClinicalRichTextSanitizer $richText): Response
    {
        abort_unless($request->user()?->can('death_records.view'), 403);

        $episode->load(['patient', 'medicalDischarge', 'deathRecord.constatedBy:id,name']);

        abort_unless($episode->deathRecord !== null, 404);

        $record = $episode->deathRecord;
        $patient = $episode->patient;

        return Inertia::render('Deaths/CertificatePrint', [
            'episode' => [
                'uuid' => $episode->uuid,
                'episode_number' => $episode->episode_number,
            ],
            // Un acte signé avant l'amendement ne portait pas ces champs :
            // il se lit sur le dossier, jamais sur une valeur inventée.
            'patient' => [
                'patient_number' => $patient?->patient_number,
                'first_name' => $patient?->first_name,
                'last_name' => $patient?->last_name,
                'birth_date' => $patient?->birth_date?->toDateString(),
                'birth_date_is_approximate' => (bool) $patient?->birth_date_is_approximate,
                'age' => $patient?->birth_date?->age ?? $patient?->declared_age,
                'birth_place' => $record->birth_place ?? $patient?->birth_place,
                'sex' => $patient?->sex?->value ?? $patient?->sex,
                'address' => $record->address ?? $patient?->address,
            ],
            'record' => [
                'father_name' => $record->father_name,
                'mother_name' => $record->mother_name,
                'identity_document_number' => $record->identity_document_number ?? $patient?->identity_document_number,
                'identity_document_issued_on' => $record->identity_document_issued_on?->toDateString(),
                'identity_document_issued_place' => $record->identity_document_issued_place,
                'death_occurred_at' => $record->death_occurred_at,
                'death_place' => $record->death_place,
                'death_causes_html' => $richText->displayHtml($record->death_causes),
                'observations_html' => $richText->displayHtml($record->observations),
                'signed_place' => $record->signed_place ?? config('rivo.site.name'),
                'constated_at' => $record->constated_at,
                'constated_by' => $record->constatedBy?->name,
            ],
        ]);
    }
}
