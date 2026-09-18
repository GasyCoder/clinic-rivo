<?php

namespace App\Actions\Medicine;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Models\ClinicalProtocol;
use App\Models\ClinicalProtocolLine;
use App\Models\DiagnosticCatalog;
use App\Models\Medicine;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Medicine\ClinicalProtocolMatcher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-111 — rédige ou corrige un protocole thérapeutique.
 *
 * Un protocole est du paramétrage : le corriger ne réécrit aucun diagnostic
 * ni aucune ordonnance déjà enregistrés — ils gardent leur propre copie de ce
 * qui a été prescrit. Ses lignes sont donc remplacées à chaque enregistrement,
 * et l'audit conserve l'ancienne et la nouvelle ordonnance type.
 */
class SaveClinicalProtocolAction
{
    public function __construct(private readonly Auditor $auditor) {}

    /** @param array<string, mixed> $data */
    public function execute(?ClinicalProtocol $protocol, array $data, User $actor): ClinicalProtocol
    {
        if ($actor->cannot('clinical_protocols.manage')) {
            throw new AuthorizationException('Vous ne pouvez pas rédiger de protocole thérapeutique.');
        }

        return DB::transaction(function () use ($protocol, $data, $actor): ClinicalProtocol {
            $catalog = DiagnosticCatalog::query()
                ->where('uuid', $data['diagnostic_catalog_uuid'])
                ->where('is_active', true)
                ->first();

            if (! $catalog) {
                throw ValidationException::withMessages([
                    'diagnostic_catalog_uuid' => 'Ce diagnostic est introuvable ou désactivé dans le référentiel.',
                ]);
            }

            $lines = array_values($data['lines'] ?? []);
            $medicines = $this->medicines($lines);

            $protocol = $protocol
                ? ClinicalProtocol::query()->lockForUpdate()->findOrFail($protocol->getKey())
                : new ClinicalProtocol(['created_by' => $actor->getKey()]);

            $before = $protocol->exists ? $this->snapshot($protocol) : null;

            $protocol->fill([
                'diagnostic_catalog_id' => $catalog->getKey(),
                'name' => trim($data['name']),
                'indications' => $this->indications($data['indications'] ?? []),
                'min_age_years' => $data['min_age_years'] ?? null,
                'max_age_years' => $data['max_age_years'] ?? null,
                'sex' => $data['sex'] ?? null,
                'min_weight_kg' => $data['min_weight_kg'] ?? null,
                'max_weight_kg' => $data['max_weight_kg'] ?? null,
                'notes' => $this->nullable($data['notes'] ?? null),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'updated_by' => $actor->getKey(),
            ])->save();

            // Sans l'ordre de `lines()` : un DELETE … ORDER BY n'est pas
            // portable d'un moteur de base à l'autre.
            ClinicalProtocolLine::query()->where('clinical_protocol_id', $protocol->getKey())->delete();

            foreach ($lines as $position => $line) {
                $protocol->lines()->create([
                    'medicine_id' => $medicines[$line['medicine_uuid']]->getKey(),
                    'dosage' => $this->nullable($line['dosage'] ?? null),
                    'route' => $this->nullable($line['route'] ?? null),
                    'frequency' => trim($line['frequency']),
                    'duration' => $this->nullable($line['duration'] ?? null),
                    'quantity' => ($line['quantity'] ?? null) !== null && $line['quantity'] !== '' ? (int) $line['quantity'] : null,
                    'instructions' => $this->nullable($line['instructions'] ?? null),
                    'sort_order' => $position,
                ]);
            }

            $this->auditor->record(
                $before === null ? 'clinical_protocol.create' : 'clinical_protocol.update',
                entity: $protocol,
                newValues: $this->snapshot($protocol->fresh()),
                oldValues: $before ?? [],
                module: 'medicine',
                actor: $actor,
            );

            return $protocol->fresh(['diagnosticCatalog', 'lines.medicine.catalogItem']);
        });
    }

    /**
     * Les médicaments d'une ordonnance type : actifs, au référentiel
     * Pharmacie, une seule fois chacun. Un protocole ne propose jamais un
     * produit que la clinique ne peut pas délivrer.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, Medicine>
     */
    private function medicines(array $lines): array
    {
        $uuids = array_column($lines, 'medicine_uuid');

        if (count($uuids) !== count(array_unique($uuids))) {
            throw ValidationException::withMessages([
                'lines' => 'Un même médicament ne peut figurer qu’une fois dans un protocole.',
            ]);
        }

        $medicines = Medicine::query()
            ->where('active', true)
            ->with('catalogItem:id,uuid')
            ->whereHas('catalogItem', fn ($query) => $query
                ->whereIn('uuid', $uuids)
                ->where('type', CatalogItemType::Medicine->value)
                ->where('module', CatalogModule::Pharmacy->value)
                ->where('stockable', true))
            ->get()
            ->keyBy(fn (Medicine $medicine) => $medicine->catalogItem->uuid);

        foreach ($lines as $index => $line) {
            if (! $medicines->has($line['medicine_uuid'])) {
                throw ValidationException::withMessages([
                    "lines.{$index}.medicine_uuid" => 'Ce médicament n’est plus actif au référentiel Pharmacie.',
                ]);
            }
        }

        return $medicines->all();
    }

    /**
     * Un signe par entrée, sans doublon ni entrée vide. La comparaison se
     * fera sans accents ni casse : « Fièvre » et « fievre » sont le même
     * signe, le garder deux fois gonflerait son poids dans le classement.
     *
     * @param  array<int, mixed>  $indications
     * @return array<int, string>
     */
    private function indications(array $indications): array
    {
        $seen = [];
        $kept = [];

        foreach ($indications as $sign) {
            $sign = trim((string) $sign);
            $key = ClinicalProtocolMatcher::normalize($sign);

            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $kept[] = $sign;
        }

        return $kept;
    }

    /** @return array<string, mixed> */
    private function snapshot(ClinicalProtocol $protocol): array
    {
        return [
            'diagnostic_catalog_id' => $protocol->diagnostic_catalog_id,
            'name' => $protocol->name,
            'indications' => $protocol->indications,
            'min_age_years' => $protocol->min_age_years,
            'max_age_years' => $protocol->max_age_years,
            'sex' => $protocol->sex?->value,
            'min_weight_kg' => $protocol->min_weight_kg,
            'max_weight_kg' => $protocol->max_weight_kg,
            'is_active' => $protocol->is_active,
            'lines' => $protocol->lines()
                ->with('medicine.catalogItem:id,name')
                ->get()
                ->map(fn ($line) => [
                    'medicine' => $line->medicine?->catalogItem?->name,
                    'dosage' => $line->dosage,
                    'route' => $line->route?->value,
                    'frequency' => $line->frequency,
                    'duration' => $line->duration,
                    'quantity' => $line->quantity,
                ])
                ->all(),
        ];
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
