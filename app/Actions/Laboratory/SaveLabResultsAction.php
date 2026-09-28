<?php

namespace App\Actions\Laboratory;

use App\Enums\LabEntryMode;
use App\Enums\LabItemStatus;
use App\Models\AnalysisCatalog;
use App\Models\LabAntibiogram;
use App\Models\LabBacterium;
use App\Models\LabRequestItem;
use App\Models\LabResult;
use App\Models\User;
use App\Services\Laboratory\AnalysisReferenceResolver;
use App\Services\Laboratory\LabWorkbench;
use App\Support\Laboratory\LabEntryOptions;
use App\Support\Laboratory\LabReferenceRange;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-213 — enregistrer la saisie de la paillasse pour une analyse demandée.
 *
 * Appelée par l'enregistrement automatique : la saisie reste ouverte tant que
 * l'analyse n'est pas terminée. Une valeur vidée retire sa ligne (c'est un
 * brouillon, pas un résultat rendu). Rien n'est rendu aux prescripteurs ici :
 * seul « Terminer » le fait (`CompleteLabItemAction`).
 *
 * L'interprétation (normal / pathologique) est celle que le technicien choisit ;
 * s'il n'en a pas choisi, elle est proposée depuis la référence (hors bornes →
 * pathologique) ou le score de Nugent — jamais un diagnostic.
 */
class SaveLabResultsAction
{
    public function __construct(
        private readonly LabWorkbench $workbench,
        private readonly AnalysisReferenceResolver $references,
    ) {}

    /** @param  array<string, mixed>  $payload */
    public function execute(LabRequestItem $item, array $payload, User $actor): LabRequestItem
    {
        if ($actor->cannot('laboratory_results.create')) {
            throw new AuthorizationException('La saisie des résultats demande le droit « laboratory_results.create ».');
        }

        return DB::transaction(function () use ($item, $payload, $actor): LabRequestItem {
            $locked = LabItemGuard::lockEditable($item);
            $definitions = $this->workbench->definitions($locked)->pluck('analysis')->keyBy('uuid');
            $patient = $this->workbench->patient($locked);
            $date = $this->workbench->referenceDate($locked);

            foreach (array_values($payload['results'] ?? []) as $index => $entry) {
                /** @var AnalysisCatalog|null $analysis */
                $analysis = $definitions->get($entry['analysis_uuid'] ?? '');
                if ($analysis === null) {
                    throw ValidationException::withMessages(["results.{$index}.analysis_uuid" => 'Cette analyse n’appartient pas à la prestation demandée.']);
                }

                $mode = LabEntryMode::for($analysis);
                if (! $mode->takesResult() || $analysis->level === AnalysisCatalog::CONTAINER_LEVEL) {
                    continue;
                }

                $normalized = $this->normalize($analysis, $mode, $entry, $index);
                $existing = LabResult::query()->where('lab_request_item_id', $locked->id)->where('analysis_catalog_id', $analysis->id)->first();

                if ($normalized === null) {
                    if ($existing !== null) {
                        $this->syncAntibiograms($locked, $analysis, []);
                        $existing->delete();
                    }

                    continue;
                }

                $reference = $existing?->reference_snapshot
                    ?? $this->references->resolve($analysis, $patient, $date)['value'];
                $rangeFlag = $mode === LabEntryMode::Numeric ? LabReferenceRange::parse($reference)?->flag($normalized['value']) : null;
                $interpretation = $this->interpretation($mode, $entry, $normalized, $rangeFlag, $existing);

                LabResult::query()->updateOrCreate(
                    ['lab_request_item_id' => $locked->id, 'analysis_catalog_id' => $analysis->id],
                    [
                        'designation_snapshot' => $analysis->designation,
                        'entry_mode' => $mode->value,
                        'unit_snapshot' => $analysis->unit,
                        'reference_snapshot' => $reference,
                        'value' => $normalized['value'],
                        'selections' => $normalized['selections'],
                        'interpretation' => $interpretation,
                        'range_flag' => $rangeFlag,
                        'entered_by' => $actor->getKey(),
                    ],
                );

                if ($mode === LabEntryMode::Culture) {
                    $this->syncAntibiograms(
                        $locked,
                        $analysis,
                        $normalized['value'] === LabEntryOptions::CULTURE_GROWTH ? ($normalized['selections']['bacteria'] ?? []) : [],
                    );
                }
            }

            $changes = [];
            if (array_key_exists('conclusion', $payload)) {
                $changes['conclusion'] = filled($payload['conclusion']) ? trim((string) $payload['conclusion']) : null;
            }
            if ($locked->started_at === null) {
                $changes['started_at'] = now();
                $changes['started_by'] = $actor->getKey();
            }
            if ($locked->currentStatus() === LabItemStatus::Pending) {
                $changes['status'] = LabItemStatus::InProgress;
            }
            if ($changes !== []) {
                $locked->update($changes);
            }

            return $locked->fresh();
        });
    }

    /**
     * Ce que la ligne portera, ou `null` si rien n'est saisi.
     *
     * @param  array<string, mixed>  $entry
     * @return array{value: ?string, selections: ?array}|null
     */
    private function normalize(AnalysisCatalog $analysis, LabEntryMode $mode, array $entry, int $index): ?array
    {
        $value = is_scalar($entry['value'] ?? null) ? trim((string) $entry['value']) : '';
        $selections = is_array($entry['selections'] ?? null) ? $entry['selections'] : [];
        $choices = array_values($analysis->predefined_values ?? []);
        $fail = fn (string $message) => throw ValidationException::withMessages(["results.{$index}.value" => "{$analysis->designation} : {$message}"]);
        $detail = is_scalar($selections['detail'] ?? null) ? trim((string) $selections['detail']) : '';

        switch ($mode) {
            case LabEntryMode::Numeric:
                if ($value === '') {
                    return null;
                }
                LabReferenceRange::numeric($value) !== null || $fail('saisissez un nombre (ex. 12,5).');

                return ['value' => str_replace(',', '.', $value), 'selections' => null];

            case LabEntryMode::Text:
                return $value === '' ? null : ['value' => mb_substr($value, 0, 2000), 'selections' => null];

            case LabEntryMode::Choice:
                if ($value === '') {
                    return null;
                }
                ($choices === [] || in_array($value, $choices, true)) || $fail('choisissez une valeur de la liste.');

                return ['value' => $value, 'selections' => null];

            case LabEntryMode::MultiChoice:
                $picked = array_values(array_unique(array_filter(array_map('strval', $selections), 'filled')));
                if ($picked === []) {
                    return null;
                }
                array_diff($picked, $choices) === [] || $fail('choisissez des valeurs de la liste.');

                return ['value' => implode(', ', $picked), 'selections' => $picked];

            case LabEntryMode::NegativePositive:
            case LabEntryMode::NegativePositiveValue:
            case LabEntryMode::NegativePositiveChoice:
                if ($value === '') {
                    return null;
                }
                in_array($value, [LabEntryOptions::NEGATIVE, LabEntryOptions::POSITIVE], true) || $fail('choisissez Négatif ou Positif.');
                if ($mode === LabEntryMode::NegativePositiveChoice && $detail !== '' && $choices !== []) {
                    in_array($detail, $choices, true) || $fail('choisissez une précision de la liste.');
                }

                return ['value' => $value, 'selections' => $mode !== LabEntryMode::NegativePositive && $detail !== '' ? ['detail' => mb_substr($detail, 0, 255)] : null];

            case LabEntryMode::AbsencePresence:
                if ($value === '') {
                    return null;
                }
                in_array($value, [LabEntryOptions::ABSENT, LabEntryOptions::PRESENT], true) || $fail('choisissez Absence ou Présence.');

                return ['value' => $value, 'selections' => null];

            case LabEntryMode::Culture:
                if ($value === '') {
                    return null;
                }
                array_key_exists($value, LabEntryOptions::CULTURE) || $fail('choisissez l’issue de la culture.');
                $bacteria = [];
                if ($value === LabEntryOptions::CULTURE_GROWTH) {
                    $wanted = array_values(array_unique(array_map('strval', (array) ($selections['bacteria'] ?? []))));
                    $bacteria = LabBacterium::query()->whereIn('uuid', $wanted)->where('is_active', true)->pluck('uuid')->all();
                    count($bacteria) === count($wanted) || $fail('un germe choisi n’existe pas ou n’est plus actif.');
                    count($bacteria) <= 6 || $fail('six germes au plus par culture.');
                }
                $other = is_scalar($selections['other'] ?? null) ? trim((string) $selections['other']) : '';

                return ['value' => $value, 'selections' => [
                    'bacteria' => $bacteria,
                    'other' => $value === LabEntryOptions::CULTURE_OTHER && $other !== '' ? mb_substr($other, 0, 500) : null,
                ]];

            case LabEntryMode::Nugent:
                $parts = [];
                foreach (LabEntryOptions::NUGENT_PARTS as $key => $part) {
                    $raw = $selections[$key] ?? null;
                    if ($raw === null || $raw === '') {
                        continue;
                    }
                    (is_numeric($raw) && (int) $raw == $raw && $raw >= 0 && $raw <= $part['max']) || $fail("{$part['label']} : un score de 0 à {$part['max']}.");
                    $parts[$key] = (int) $raw;
                }
                if ($parts === []) {
                    return null;
                }

                return ['value' => count($parts) === count(LabEntryOptions::NUGENT_PARTS) ? (string) array_sum($parts) : null, 'selections' => $parts];

            default:
                return null;
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array{value: ?string, selections: ?array}  $normalized
     */
    private function interpretation(LabEntryMode $mode, array $entry, array $normalized, ?string $rangeFlag, ?LabResult $existing): ?string
    {
        if (! $mode->interpretable()) {
            return null;
        }

        if (array_key_exists('interpretation', $entry)) {
            $chosen = $entry['interpretation'];
            if ($chosen === null || $chosen === '') {
                return null;
            }
            if (! in_array($chosen, LabResult::INTERPRETATIONS, true)) {
                throw ValidationException::withMessages(['results' => 'Interprétation inconnue.']);
            }

            return $chosen;
        }

        return match (true) {
            $rangeFlag === LabReferenceRange::NORMAL => 'NORMAL',
            in_array($rangeFlag, [LabReferenceRange::LOW, LabReferenceRange::HIGH], true) => 'PATHOLOGICAL',
            $mode === LabEntryMode::Nugent && $normalized['value'] !== null => LabEntryOptions::nugent((int) $normalized['value'])['suggested'],
            default => $existing?->interpretation,
        };
    }

    /** Un antibiogramme par germe retenu ; celui d'un germe retiré part avec lui (brouillon). */
    private function syncAntibiograms(LabRequestItem $item, AnalysisCatalog $analysis, array $bacteriumUuids): void
    {
        $bacteria = LabBacterium::query()->whereIn('uuid', $bacteriumUuids)->get(['id', 'name']);

        LabAntibiogram::query()
            ->where('lab_request_item_id', $item->id)
            ->where('analysis_catalog_id', $analysis->id)
            ->whereNotIn('bacterium_id', $bacteria->pluck('id'))
            ->get()
            ->each(function (LabAntibiogram $antibiogram): void {
                $antibiogram->results()->get()->each->delete();
                $antibiogram->delete();
            });

        foreach ($bacteria as $bacterium) {
            LabAntibiogram::query()->firstOrCreate(
                ['lab_request_item_id' => $item->id, 'analysis_catalog_id' => $analysis->id, 'bacterium_id' => $bacterium->id],
                ['bacterium_name_snapshot' => $bacterium->name],
            );
        }
    }
}
