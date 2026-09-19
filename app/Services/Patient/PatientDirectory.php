<?php

namespace App\Services\Patient;

use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodePriority;
use App\Enums\EpisodeStatus;
use App\Enums\PatientType;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Les filtres du répertoire des patients, lus une seule fois (ADR-119, 120, 133).
 *
 * L'écran et l'export Excel filtrent **exactement** de la même façon : un export
 * qui ne reprendrait pas ce que l'écran affiche serait une seconde vérité. Les
 * compteurs de l'écran sont des facettes — chacun annonce ce que donnerait un
 * clic sur sa case, les autres filtres inchangés — d'où les méthodes qui lèvent
 * un seul filtre à la fois.
 */
final class PatientDirectory
{
    public const SORTS = ['recent', 'name_asc', 'name_desc'];

    public const SEGMENTS = ['all', 'normal', 'vip'];

    /** @param array<int, string>|null $need */
    private function __construct(
        public readonly string $search,
        public readonly ?string $type,
        public readonly ?string $emergency,
        public readonly ?array $need,
        public readonly ?string $status,
        public readonly string $segment,
        public readonly ?string $letter,
        public readonly string $sort,
        public readonly PatientServiceNeeds $needs,
        public readonly PatientVipClassifier $vip,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $type = in_array($request->query('type'), array_column(PatientType::cases(), 'value'), true)
            ? $request->query('type')
            : null;
        $emergency = in_array($request->query('emergency'), ['active', 'none'], true) ? $request->query('emergency') : null;
        $status = in_array($request->query('status'), ['open', 'settlement', 'none'], true) ? $request->query('status') : null;
        $segment = in_array($request->query('segment'), self::SEGMENTS, true) ? $request->query('segment') : 'all';
        $sort = in_array($request->query('sort'), self::SORTS, true) ? $request->query('sort') : 'recent';

        // Une initiale : une seule lettre A–Z, jamais un motif libre (`%`, `_`).
        $letter = strtoupper(trim((string) $request->query('letter', '')));
        $letter = preg_match('/^[A-Z]$/', $letter) === 1 ? $letter : null;

        return new self(
            trim((string) $request->query('q', '')),
            $type,
            $emergency,
            // ADR-119 : `null` = tous, `[]` = aucun de ces services, sinon la
            // combinaison exacte.
            PatientServiceNeeds::parse($request->query('need')),
            $status,
            $segment,
            $letter,
            $sort,
            PatientServiceNeeds::current(),
            PatientVipClassifier::current(),
        );
    }

    /**
     * Recherche, type, urgence, initiale et catégorie : les filtres que tous
     * les compteurs respectent. `$withSegment = false` lève la catégorie pour
     * compter chacune de ses cases.
     */
    public function filtered(bool $withSegment = true): Builder
    {
        $search = $this->search;
        $emergencyOpen = fn ($episode) => $episode
            ->where('priority', EpisodePriority::Emergency->value)
            ->where('status', EpisodeStatus::Open->value);

        return Patient::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('patient_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($this->type, fn ($query) => $query->where('patient_type', $this->type))
            ->when($this->emergency === 'active', fn ($query) => $query->whereHas('episodes', $emergencyOpen))
            ->when($this->emergency === 'none', fn ($query) => $query->whereDoesntHave('episodes', $emergencyOpen))
            // Un nom qui commence par « É » se range sous « E » : la collation
            // du site ne distingue ni les accents ni la casse.
            ->when($this->letter, fn ($query) => $query->where('last_name', 'like', $this->letter.'%'))
            ->when($withSegment, fn ($query) => $this->segmentScope($query, $this->segment));
    }

    /** ADR-133 — VIP ou non, d'après les seuils du site. */
    public function segmentScope(Builder $query, ?string $value): Builder
    {
        return match ($value) {
            'vip' => $query->whereIn('patients.id', $this->vip->vipIds()),
            'normal' => $query->whereNotIn('patients.id', $this->vip->vipIds()),
            default => $query,
        };
    }

    /** ADR-120 — l'état que la ligne affiche déjà : en cours, en attente de règlement, aucun passage. */
    public function statusScope(Builder $query, ?string $value): Builder
    {
        $open = fn ($episode) => $episode->where('status', EpisodeStatus::Open->value);
        $inCare = fn ($episode) => $open($episode)
            ->where('administrative_status', '!=', EpisodeAdministrativeStatus::PendingSettlement->value);
        $emergencyOpen = fn ($episode) => $open($episode)->where('priority', EpisodePriority::Emergency->value);

        return match ($value) {
            'open' => $query->where(fn ($q) => $q->whereHas('episodes', $inCare)->orWhereHas('episodes', $emergencyOpen)),
            'settlement' => $query->whereHas('episodes', fn ($episode) => $open($episode)
                ->where('administrative_status', EpisodeAdministrativeStatus::PendingSettlement->value))
                ->whereDoesntHave('episodes', $inCare)
                ->whereDoesntHave('episodes', $emergencyOpen),
            'none' => $query->whereDoesntHave('episodes', $open),
            default => $query,
        };
    }

    /** @param array<int, string>|null $value */
    public function needScope(Builder $query, ?array $value): Builder
    {
        return match (true) {
            $value === null => $query,
            $value === [] => $query->whereNotIn('patients.id', $this->needs->patientIds()),
            default => $query->whereIn('patients.id', $this->needs->idsMatching($value)),
        };
    }

    /** Tous les filtres appliqués — ce que l'écran liste et ce que l'export écrit. */
    public function query(): Builder
    {
        return $this->needScope($this->statusScope($this->filtered(), $this->status), $this->need);
    }

    /**
     * A → Z par nom puis prénom, ou Z → A ; sinon les plus récents d'abord
     * (le comportement d'origine). L'identifiant départage les homonymes : sans
     * lui, deux pages consécutives pourraient se recouvrir.
     */
    public function ordered(Builder $query): Builder
    {
        return match ($this->sort) {
            'name_asc' => $query->orderBy('last_name')->orderBy('first_name')->orderBy('patients.id'),
            'name_desc' => $query->orderByDesc('last_name')->orderByDesc('first_name')->orderByDesc('patients.id'),
            default => $query->orderByDesc('patients.id'),
        };
    }
}
