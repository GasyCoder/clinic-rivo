<?php

namespace App\Services\Laboratory;

use App\Enums\LabEntryMode;
use App\Models\LabRequestItem;
use App\Models\LabResult;
use App\Support\Laboratory\LabEntryOptions;

/**
 * ADR-213 — le résultat rendu d'une analyse, en texte, dans
 * `lab_request_items.result_value` : c'est ce que la Médecine, la Maternité, le
 * séjour et le dossier lisent déjà. Composé depuis les lignes saisies, jamais
 * saisi deux fois.
 */
class LabResultSummary
{
    public function __construct(private readonly LabWorkbench $workbench) {}

    public function compose(LabRequestItem $item): string
    {
        $item->loadMissing(['results', 'antibiograms.results']);
        $results = $item->results->keyBy('analysis_catalog_id');
        $lines = [];

        foreach ($this->workbench->definitions($item) as $row) {
            $analysis = $row['analysis'];
            $result = $results->get($analysis->id);
            $indent = str_repeat('  ', $row['depth']);
            $mode = LabEntryMode::for($analysis);

            if ($result === null) {
                if (! $mode->takesResult() || $analysis->level === 'PARENT') {
                    $lines[] = $indent.$analysis->designation;
                }

                continue;
            }

            $lines[] = $indent.$result->designation_snapshot.' : '.$this->valueText($result);

            if ($mode === LabEntryMode::Culture) {
                foreach ($item->antibiograms->where('analysis_catalog_id', $analysis->id) as $antibiogram) {
                    $groups = $antibiogram->results->groupBy('interpretation')
                        ->map(fn ($group) => $group->pluck('antibiotic_name_snapshot')->implode(', '));
                    $parts = collect(['S' => 'Sensible', 'I' => 'Intermédiaire', 'R' => 'Résistant'])
                        ->filter(fn ($label, $key) => $groups->has($key))
                        ->map(fn ($label, $key) => "{$label} : {$groups->get($key)}");
                    $lines[] = $indent.'  Antibiogramme '.$antibiogram->bacterium_name_snapshot
                        .($parts->isNotEmpty() ? ' — '.$parts->implode(' ; ') : ' — non renseigné');
                }
            }
        }

        $text = trim(implode("\n", $lines));
        if (filled($item->conclusion)) {
            $text .= ($text !== '' ? "\n\n" : '').'Conclusion : '.$item->conclusion;
        }

        return $text;
    }

    public function valueText(LabResult $result): string
    {
        $selections = $result->selections ?? [];
        $text = match ($result->entry_mode) {
            LabEntryMode::Culture->value => (LabEntryOptions::CULTURE[$result->value] ?? (string) $result->value)
                .($result->value === LabEntryOptions::CULTURE_OTHER && filled($selections['other'] ?? null) ? ' — '.$selections['other'] : '')
                .($result->value === LabEntryOptions::CULTURE_GROWTH
                    ? ' : '.$result->item->antibiograms->where('analysis_catalog_id', $result->analysis_catalog_id)->pluck('bacterium_name_snapshot')->implode(', ')
                    : ''),
            LabEntryMode::Nugent->value => $result->value !== null
                ? "score {$result->value}/10 — ".LabEntryOptions::nugent((int) $result->value)['label']
                : 'score incomplet',
            default => trim((string) $result->value.(filled($selections['detail'] ?? null) ? ' — '.$selections['detail'] : '')),
        };

        if (filled($result->unit_snapshot) && $result->entry_mode === LabEntryMode::Numeric->value) {
            $text .= ' '.$result->unit_snapshot;
        }
        if (filled($result->reference_snapshot)) {
            $text .= " (réf. {$result->reference_snapshot})";
        }
        if ($result->interpretation === 'PATHOLOGICAL') {
            $text .= ' — pathologique';
        }
        if ($result->is_critical) {
            $text .= ' — CRITIQUE';
        }

        return $text;
    }
}
