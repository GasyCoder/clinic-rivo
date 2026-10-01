<?php

namespace App\Support\Laboratory;

use App\Models\AnalysisCatalog;
use App\Models\Patient;
use App\Services\Laboratory\AnalysisReferenceResolver;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * ADR-214 — les bornes critiques d'une analyse, saisies par la clinique dans le
 * catalogue (jamais inventées) : en deçà de la borne basse ou au-delà de la
 * borne haute, le résultat est marqué critique d'office. Le laboratoire peut
 * retirer la marque ; sans borne saisie, rien ne change (ADR-213).
 *
 * Elles se règlent par profil, comme les références : générale, homme, femme,
 * enfant garçon, enfant fille ; le profil le plus précis l'emporte.
 */
final class LabCriticalRange
{
    public const PROFILES = [
        'general' => 'Générale',
        'male' => 'Homme',
        'female' => 'Femme',
        'child_male' => 'Enfant · garçon',
        'child_female' => 'Enfant · fille',
    ];

    public const LOW = 'LOW';

    public const HIGH = 'HIGH';

    private function __construct(
        public readonly ?float $low,
        public readonly ?float $high,
        public readonly string $profile,
    ) {}

    /** Les bornes qui valent pour ce patient, ou `null` quand le catalogue n'en porte aucune. */
    public static function resolve(AnalysisCatalog $analysis, Patient $patient, CarbonInterface $date): ?self
    {
        $ranges = $analysis->critical_ranges ?? [];
        if ($ranges === []) {
            return null;
        }

        foreach (app(AnalysisReferenceResolver::class)->profiles($patient, $date) as [$key, $label]) {
            $range = $ranges[$key] ?? null;
            if (is_array($range) && (isset($range['low']) || isset($range['high']))) {
                return new self(
                    isset($range['low']) ? (float) $range['low'] : null,
                    isset($range['high']) ? (float) $range['high'] : null,
                    $label,
                );
            }
        }

        return null;
    }

    /** BAS ou HAUT critique ; `null` quand la valeur n'est pas un nombre ou reste entre les bornes. */
    public function flag(?string $value): ?string
    {
        $number = LabReferenceRange::numeric($value);

        return match (true) {
            $number === null => null,
            $this->low !== null && $number < $this->low => self::LOW,
            $this->high !== null && $number > $this->high => self::HIGH,
            default => null,
        };
    }

    /** « < 2,5 ou > 6,5 », tel qu'il s'imprime et se fige sur le résultat. */
    public function describe(): string
    {
        $format = fn (float $value) => rtrim(rtrim(number_format($value, 3, ',', ''), '0'), ',');

        return collect([
            $this->low !== null ? '< '.$format($this->low) : null,
            $this->high !== null ? '> '.$format($this->high) : null,
        ])->filter()->join(' ou ');
    }

    /** @return array{low: ?float, high: ?float, profile: string, text: string} */
    public function toArray(): array
    {
        return ['low' => $this->low, 'high' => $this->high, 'profile' => $this->profile, 'text' => $this->describe()];
    }

    /**
     * Les règles de saisie des bornes critiques, pour un préfixe de champ
     * (« », « children.*. », « children.*.children.*. »). Le détail — un nombre,
     * la basse sous la haute — est relu par `normalize()`.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(string $prefix = ''): array
    {
        return [
            "{$prefix}critical_ranges" => ['sometimes', 'nullable', 'array:'.implode(',', array_keys(self::PROFILES))],
            "{$prefix}critical_ranges.*" => ['nullable', 'array:low,high'],
            "{$prefix}critical_ranges.*.low" => ['nullable', 'max:20'],
            "{$prefix}critical_ranges.*.high" => ['nullable', 'max:20'],
        ];
    }

    /**
     * Ce que le catalogue enregistre : les seuls profils qui portent une borne,
     * en nombres, la basse sous la haute. `null` quand rien n'est saisi.
     *
     * @param  array<string, mixed>|null  $input
     * @return array<string, array{low: ?float, high: ?float}>|null
     */
    public static function normalize(?array $input, string $errorKey = 'critical_ranges'): ?array
    {
        $clean = [];
        foreach (self::PROFILES as $key => $label) {
            $range = is_array($input[$key] ?? null) ? $input[$key] : [];
            $bounds = [];
            foreach (['low', 'high'] as $bound) {
                $raw = $range[$bound] ?? null;
                if ($raw === null || trim((string) $raw) === '') {
                    $bounds[$bound] = null;

                    continue;
                }
                $number = LabReferenceRange::numeric((string) $raw);
                if ($number === null) {
                    throw ValidationException::withMessages(["{$errorKey}.{$key}.{$bound}" => "Borne critique « {$label} » : saisissez un nombre (ex. 2,5)."]);
                }
                $bounds[$bound] = $number;
            }
            if ($bounds['low'] === null && $bounds['high'] === null) {
                continue;
            }
            if ($bounds['low'] !== null && $bounds['high'] !== null && $bounds['low'] >= $bounds['high']) {
                throw ValidationException::withMessages(["{$errorKey}.{$key}.low" => "Borne critique « {$label} » : la borne basse doit rester sous la borne haute."]);
            }
            $clean[$key] = $bounds;
        }

        return $clean === [] ? null : $clean;
    }
}
