<?php

namespace App\Services\Administration;

use App\Enums\HrReferenceType;
use App\Models\HrReferenceValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * ADR-194 — un stage est un contrat dont le type est marqué « contrat de
 * stage » dans Paramètres RH. La règle vit ici une seule fois : la liste des
 * stages, le repère « Stagiaire » de l'annuaire et le formulaire de contrat
 * la lisent tous les trois.
 *
 * Le marquage est lu sur le type, jamais sur son code ni son libellé : un
 * type renommé « Stage académique » reste un contrat de stage.
 */
class InternshipDirectory
{
    /** @var Collection<int, int>|null */
    private ?Collection $typeIds = null;

    /** @return Collection<int, int> */
    public function contractTypeIds(): Collection
    {
        return $this->typeIds ??= HrReferenceValue::withTrashed()
            ->ofType(HrReferenceType::ContractType)
            ->get()
            ->filter(fn (HrReferenceValue $type) => $type->isInternshipContractType())
            ->pluck('id')
            ->values();
    }

    public function hasInternshipType(): bool
    {
        return HrReferenceValue::query()->ofType(HrReferenceType::ContractType)
            ->where('active', true)->get()
            ->contains(fn (HrReferenceValue $type) => $type->isInternshipContractType());
    }

    /** Les contrats de stage. */
    public function internships(Builder|Relation $query): Builder|Relation
    {
        return $query->whereIn('contract_type_id', $this->contractTypeIds()->all());
    }

    /** Les contrats en cours aujourd'hui. */
    public function current(Builder|Relation $query): Builder|Relation
    {
        $today = now()->toDateString();

        return $query->whereDate('starts_on', '<=', $today)
            ->where(fn ($period) => $period->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today));
    }

    /** Un stage en cours aujourd'hui. */
    public function currentInternships(Builder|Relation $query): Builder|Relation
    {
        return $this->current($this->internships($query));
    }
}
