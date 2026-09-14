<?php

namespace App\Services\Administration;

use App\Enums\DocumentDataContext;

/**
 * The declarative schema of "page 1" fields the RH fills in at generation
 * time (ADR-087), by data context. Purely descriptive — no data resolution
 * here, see DocumentFormDataResolver for that. Reuses the same field
 * inventory the removed {{variable}} catalogue used to expose.
 */
class DocumentFormFieldCatalog
{
    /** @return array<int, array{key: string, label: string, type: string, required: bool}> */
    public function fieldsForContext(DocumentDataContext $context): array
    {
        $fields = $this->employeeFields();

        return match ($context) {
            DocumentDataContext::EmployeeOnly => $fields,
            DocumentDataContext::EmployeeAndContract => [...$fields, ...$this->contractFields()],
            DocumentDataContext::EmployeeAndLeave => [...$fields, ...$this->leaveFields()],
        };
    }

    /** @return array<int, array{key: string, label: string, type: string, required: bool}> */
    private function employeeFields(): array
    {
        return [
            ['key' => 'nom', 'label' => 'Nom', 'type' => 'text', 'required' => true],
            ['key' => 'prenom', 'label' => 'Prénom(s)', 'type' => 'text', 'required' => true],
            ['key' => 'matricule', 'label' => 'Matricule', 'type' => 'text', 'required' => true],
            ['key' => 'poste', 'label' => 'Fonction', 'type' => 'text', 'required' => false],
            ['key' => 'service', 'label' => 'Service / département', 'type' => 'text', 'required' => false],
            ['key' => 'date_naissance', 'label' => 'Date de naissance', 'type' => 'date', 'required' => false],
            ['key' => 'date_embauche', 'label' => 'Date d’embauche', 'type' => 'date', 'required' => false],
            ['key' => 'date', 'label' => 'Date du document', 'type' => 'date', 'required' => true],
        ];
    }

    /** @return array<int, array{key: string, label: string, type: string, required: bool}> */
    private function contractFields(): array
    {
        return [
            ['key' => 'type_contrat', 'label' => 'Type de contrat', 'type' => 'text', 'required' => true],
            ['key' => 'reference_contrat', 'label' => 'Référence du contrat', 'type' => 'text', 'required' => false],
            ['key' => 'date_signature', 'label' => 'Date de signature', 'type' => 'date', 'required' => false],
            ['key' => 'date_debut', 'label' => 'Date de début', 'type' => 'date', 'required' => true],
            ['key' => 'fin_periode_essai', 'label' => 'Fin de période d’essai', 'type' => 'date', 'required' => false],
            ['key' => 'date_fin', 'label' => 'Date de fin', 'type' => 'date', 'required' => false],
        ];
    }

    /** @return array<int, array{key: string, label: string, type: string, required: bool}> */
    private function leaveFields(): array
    {
        return [
            ['key' => 'type_conge', 'label' => 'Type de congé', 'type' => 'text', 'required' => true],
            ['key' => 'date_depart', 'label' => 'Date de départ', 'type' => 'date', 'required' => true],
            ['key' => 'date_retour', 'label' => 'Date de retour', 'type' => 'date', 'required' => true],
            ['key' => 'jours_demandes', 'label' => 'Jours demandés', 'type' => 'text', 'required' => false],
            ['key' => 'motif_conge', 'label' => 'Motif du congé', 'type' => 'text', 'required' => false],
        ];
    }
}
