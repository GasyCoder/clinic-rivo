<?php

namespace App\Services\Patient;

use App\Enums\CatalogModule;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodeStatus;
use App\Enums\PharmacyDispenseStatus;
use App\Models\EpisodeOrientation;
use App\Models\EpisodeReceptionNextStep;
use App\Models\Patient;
use App\Models\PharmacyDispense;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * ADR-119 — où chaque patient a encore besoin d'aller, maintenant.
 *
 * Le répertoire disait qu'un patient avait « un passage en cours » sans dire
 * où : la Réception devait ouvrir chaque dossier pour savoir s'il attendait le
 * médecin, les soins ou la pharmacie. Trois services suffisent à ce que la
 * Réception se demande, et chacun a sa propre définition de « attend ici » :
 *
 * ```text
 * MEDICINE  une orientation Médecine en attente ou en cours, sur un passage ouvert
 * CARE      une orientation Soins en attente ou en cours, sur un passage ouvert
 * PHARMACY  une demande de dispensation que la Pharmacie n'a pas encore terminée
 * ```
 *
 * ADR-177 — une prestation choisie à l'accueil n'ouvre plus de file : un
 * patient qui vient d'arriver n'a souvent aucune orientation. La prochaine
 * étape que la Réception a **suggérée** vers Médecine ou Soins compte donc
 * comme un besoin, à l'état `SUGGESTED`, tant qu'aucune vraie orientation vers
 * ce service n'existe sur le passage. Ce n'est qu'une lecture : la suggestion
 * n'autorise ni n'interdit rien, et une orientation réelle l'emporte toujours.
 *
 * Ce sont exactement les définitions des files de ces services (le passage
 * doit être ouvert comme aux Soins ; les statuts de dispensation sont ceux de
 * la file Pharmacie, `PharmacyDispenseStatus::openValues()`) : le répertoire et
 * la file ne peuvent pas compter deux réalités.
 *
 * L'ensemble des services d'un patient est une **combinaison exacte** :
 * « Médecine seulement » exclut celui qui attend aussi la pharmacie. C'est ce
 * qui fait de chaque patient l'objet d'une seule case, et de la somme des cases
 * le nombre de patients.
 *
 * Deux requêtes suffisent, et elles ne portent que sur les patients qui ont un
 * besoin — quelques dizaines, jamais le répertoire entier : les combinaisons se
 * calculent ici plutôt que dans une requête à trois sous-requêtes corrélées par
 * ligne de la table `patients`.
 *
 * Le besoin auprès de la Pharmacie est une information de **routage** — le
 * patient doit y passer —, de même nature que l'orientation vers un service.
 * Aucun état financier n'est servi (ni « à régler » ni « prête à délivrer »),
 * conformément à ADR-013 et à la distinction que fait l'ADR-117 entre l'issue
 * d'une ordonnance et son règlement.
 */
final class PatientServiceNeeds
{
    public const MEDICINE = 'MEDICINE';

    public const CARE = 'CARE';

    public const PHARMACY = 'PHARMACY';

    /** L'ordre dans lequel les services se listent, partout. */
    public const SERVICES = [self::MEDICINE, self::CARE, self::PHARMACY];

    public const ALL = 'ALL';

    public const NONE = 'NONE';

    public const SUGGESTED = 'SUGGESTED';

    public const PENDING = 'PENDING';

    public const IN_PROGRESS = 'IN_PROGRESS';

    public const PARTIAL = 'PARTIAL';

    /** Patient id → service → où en est ce besoin. @var array<int, array<string, array{state: string, episode_number: ?string, since: ?string}>> */
    private array $byPatient = [];

    private function __construct() {}

    public static function current(): self
    {
        $needs = new self;
        $needs->loadOrientations();
        $needs->loadSuggestions();
        $needs->loadDispenses();

        return $needs;
    }

    /**
     * Le filtre demandé, tel que l'URL le porte : `null` pour « tous », un
     * tableau vide pour « aucun de ces services », sinon la combinaison
     * exacte, dans l'ordre canonique.
     *
     * Une valeur inconnue ne filtre rien : un signet périmé montre tous les
     * patients plutôt qu'une liste vide qui se lirait « personne n'attend ».
     *
     * @return array<int, string>|null
     */
    public static function parse(mixed $value): ?array
    {
        $value = strtoupper(trim((string) $value));

        if ($value === '' || $value === self::ALL) {
            return null;
        }

        if ($value === self::NONE) {
            return [];
        }

        $tokens = array_values(array_unique(array_map('trim', explode(',', $value))));

        if (array_diff($tokens, self::SERVICES) !== []) {
            return null;
        }

        return array_values(array_intersect(self::SERVICES, $tokens));
    }

    /**
     * La clé canonique d'une combinaison : `MEDICINE,CARE`, ou `NONE`.
     *
     * @param  array<int, string>  $services
     */
    public static function key(array $services): string
    {
        $ordered = array_values(array_intersect(self::SERVICES, $services));

        return $ordered === [] ? self::NONE : implode(',', $ordered);
    }

    /**
     * Les sept combinaisons non vides des trois services, du plus simple au
     * plus complet.
     *
     * @return array<int, array<int, string>>
     */
    public static function combinations(): array
    {
        [$medicine, $care, $pharmacy] = self::SERVICES;

        return [
            [$medicine],
            [$care],
            [$pharmacy],
            [$medicine, $care],
            [$medicine, $pharmacy],
            [$care, $pharmacy],
            [$medicine, $care, $pharmacy],
        ];
    }

    /** @return array<int, int> Les patients qui ont au moins un besoin. */
    public function patientIds(): array
    {
        return array_keys($this->byPatient);
    }

    /**
     * @return array<int, string> Les services que ce patient attend, dans l'ordre canonique.
     */
    public function servicesOf(int $patientId): array
    {
        return array_values(array_intersect(self::SERVICES, array_keys($this->byPatient[$patientId] ?? [])));
    }

    /**
     * Ce que la ligne d'un patient affiche : un besoin par service, avec où il
     * en est.
     *
     * @return array<int, array{service: string, state: string, episode_number: ?string, since: ?string}>
     */
    public function forPatient(int $patientId): array
    {
        $details = $this->byPatient[$patientId] ?? [];

        return collect(self::SERVICES)
            ->filter(fn (string $service) => isset($details[$service]))
            ->map(fn (string $service) => ['service' => $service, ...$details[$service]])
            ->values()
            ->all();
    }

    /**
     * Les patients dont l'ensemble de services est exactement celui-ci.
     *
     * @param  array<int, string>  $services  Non vide : « aucun » se filtre par exclusion.
     * @return array<int, int>
     */
    public function idsMatching(array $services): array
    {
        $wanted = self::key($services);

        return array_values(array_filter(
            $this->patientIds(),
            fn (int $patientId) => self::key($this->servicesOf($patientId)) === $wanted,
        ));
    }

    /**
     * Combien de patients dans chaque combinaison, parmi ceux qui passent les
     * autres filtres du répertoire.
     *
     * Chaque compte est ce que donnerait un clic sur sa case, les autres
     * filtres inchangés : une case qui annonce 2 et ouvre une liste vide serait
     * pire que pas de compteur. La somme des cases est donc le nombre de
     * patients — « Tous » n'est pas une case de plus, c'est leur total.
     *
     * @param  Builder<Patient>  $filtered  Sans le filtre du besoin lui-même.
     * @return array<int, array{key: string, services: array<int, string>|null, count: int}>
     */
    public function facets(Builder $filtered): array
    {
        $ids = $this->patientIds();
        $total = (clone $filtered)->count();
        $withNeeds = $ids === []
            ? []
            : (clone $filtered)->whereIn('patients.id', $ids)->pluck('patients.id')->all();

        $counts = [];

        foreach ($withNeeds as $patientId) {
            $key = self::key($this->servicesOf((int) $patientId));
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $facets = [['key' => self::ALL, 'services' => null, 'count' => $total]];

        foreach (self::combinations() as $services) {
            $key = self::key($services);
            $facets[] = ['key' => $key, 'services' => $services, 'count' => $counts[$key] ?? 0];
        }

        $facets[] = ['key' => self::NONE, 'services' => [], 'count' => $total - count($withNeeds)];

        return $facets;
    }

    private function loadOrientations(): void
    {
        $rows = EpisodeOrientation::query()
            ->toBase()
            ->join('episodes', 'episodes.id', '=', 'episode_orientations.episode_id')
            ->where('episodes.status', EpisodeStatus::Open->value)
            ->whereIn('episode_orientations.destination_module', [
                CatalogModule::Medicine->value,
                CatalogModule::Care->value,
            ])
            ->whereIn('episode_orientations.status', [
                EpisodeOrientationStatus::Pending->value,
                EpisodeOrientationStatus::InProgress->value,
            ])
            // Le plus ancien d'abord : à état égal, c'est celui qui attend
            // depuis le plus longtemps que la ligne montre.
            ->orderBy('episode_orientations.oriented_at')
            ->orderBy('episode_orientations.id')
            ->get([
                'episodes.patient_id',
                'episodes.episode_number',
                'episode_orientations.destination_module',
                'episode_orientations.status',
                'episode_orientations.oriented_at',
                'episode_orientations.accepted_at',
            ]);

        foreach ($rows as $row) {
            $service = $row->destination_module === CatalogModule::Medicine->value ? self::MEDICINE : self::CARE;
            $inProgress = $row->status === EpisodeOrientationStatus::InProgress->value;

            $this->remember((int) $row->patient_id, $service, [
                'state' => $inProgress ? self::IN_PROGRESS : self::PENDING,
                'episode_number' => $row->episode_number,
                'since' => self::iso($inProgress ? ($row->accepted_at ?? $row->oriented_at) : $row->oriented_at),
            ]);
        }
    }

    /**
     * ADR-177 — la suggestion de la Réception vers Médecine ou Soins, sur un
     * passage ouvert dont le parcours clinique n'est pas clos, et tant que ce
     * service n'a aucune orientation (ni en attente, ni en cours, ni terminée)
     * sur ce passage : dès qu'il en a une, c'est elle qui dit où en est le
     * patient.
     */
    private function loadSuggestions(): void
    {
        $rows = EpisodeReceptionNextStep::query()
            ->toBase()
            ->join('episodes', 'episodes.id', '=', 'episode_reception_next_steps.episode_id')
            ->where('episodes.status', EpisodeStatus::Open->value)
            ->where('episodes.administrative_status', '!=', EpisodeAdministrativeStatus::PendingSettlement->value)
            ->whereIn('episode_reception_next_steps.module', [CatalogModule::Medicine->value, CatalogModule::Care->value])
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('episode_orientations')
                ->whereColumn('episode_orientations.episode_id', 'episodes.id')
                ->whereColumn('episode_orientations.destination_module', 'episode_reception_next_steps.module')
                ->where('episode_orientations.status', '!=', EpisodeOrientationStatus::Cancelled->value))
            ->orderBy('episodes.started_at')
            ->orderBy('episodes.id')
            ->get([
                'episodes.patient_id',
                'episodes.episode_number',
                'episodes.started_at',
                'episode_reception_next_steps.module',
            ]);

        foreach ($rows as $row) {
            $this->remember((int) $row->patient_id, $row->module === CatalogModule::Medicine->value ? self::MEDICINE : self::CARE, [
                'state' => self::SUGGESTED,
                'episode_number' => $row->episode_number,
                'since' => self::iso($row->started_at),
            ]);
        }
    }

    private function loadDispenses(): void
    {
        $rows = PharmacyDispense::query()
            ->toBase()
            ->whereNotNull('patient_id')
            ->whereIn('status', PharmacyDispenseStatus::openValues())
            ->orderBy('requested_at')
            ->orderBy('id')
            ->get(['patient_id', 'status', 'requested_at']);

        foreach ($rows as $row) {
            $this->remember((int) $row->patient_id, self::PHARMACY, [
                // Une délivrance partielle est une issue ; « à régler » et
                // « prête » sont des états financiers, qu'on ne sert pas.
                'state' => $row->status === PharmacyDispenseStatus::PartiallyDispensed->value ? self::PARTIAL : self::PENDING,
                'episode_number' => null,
                'since' => self::iso($row->requested_at),
            ]);
        }
    }

    /**
     * Garde, par patient et par service, la demande la plus avancée : en cours
     * l'emporte sur en attente, et à état égal la première rencontrée — la plus
     * ancienne — reste.
     *
     * @param  array{state: string, episode_number: ?string, since: ?string}  $detail
     */
    private function remember(int $patientId, string $service, array $detail): void
    {
        $known = $this->byPatient[$patientId][$service] ?? null;

        if ($known !== null && self::rank($known['state']) >= self::rank($detail['state'])) {
            return;
        }

        $this->byPatient[$patientId][$service] = $detail;
    }

    private static function rank(string $state): int
    {
        return match ($state) {
            self::IN_PROGRESS, self::PARTIAL => 2,
            // Une suggestion cède toujours la place à une vraie orientation.
            self::SUGGESTED => 0,
            default => 1,
        };
    }

    private static function iso(mixed $value): ?string
    {
        return $value ? CarbonImmutable::parse($value)->toIso8601String() : null;
    }
}
