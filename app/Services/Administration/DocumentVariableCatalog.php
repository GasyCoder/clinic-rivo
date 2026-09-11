<?php

namespace App\Services\Administration;

use App\Enums\DocumentDataContext;

/**
 * The catalogue of variables a canevas can auto-resolve, by data context
 * (ADR-070). Anything a template references outside this catalogue (e.g.
 * {{salaire}} — RIVO stores no payroll data, ADR-066/069) is never invented:
 * DocumentVariableResolver treats it as a manual field the RH fills in at
 * generation time.
 */
class DocumentVariableCatalog
{
    /** @return array<string, array<string, string>> group label => [code => label] */
    public function groupedForContext(DocumentDataContext $context): array
    {
        $groups = ['Personnel' => $this->employeeVariables()];

        $groups = match ($context) {
            DocumentDataContext::EmployeeOnly => $groups,
            DocumentDataContext::EmployeeAndContract => [...$groups, 'Contrat' => $this->contractVariables()],
            DocumentDataContext::EmployeeAndLeave => [...$groups, 'Congé' => $this->leaveVariables()],
        };

        return $groups;
    }

    /** @return array<string, string> code => label, flattened across all groups for this context */
    public function forContext(DocumentDataContext $context): array
    {
        return collect($this->groupedForContext($context))->collapse()->all();
    }

    /** @return array<string, string> */
    private function employeeVariables(): array
    {
        return [
            'nom' => 'Nom de l’employé',
            'prenom' => 'Prénom(s) de l’employé',
            'nom_complet' => 'Nom et prénom(s)',
            'matricule' => 'Matricule employé',
            'poste' => 'Fonction',
            'service' => 'Département / service',
            'date_naissance' => 'Date de naissance',
            'date_embauche' => 'Date d’embauche',
            'site' => 'Nom du site',
            'date' => 'Date actuelle',
        ];
    }

    /** @return array<string, string> */
    private function contractVariables(): array
    {
        return [
            'type_contrat' => 'Type de contrat',
            'reference_contrat' => 'Référence du contrat',
            'date_signature' => 'Date de signature',
            'date_debut' => 'Date de début',
            'fin_periode_essai' => 'Fin de période d’essai',
            'date_fin' => 'Date de fin',
        ];
    }

    /** @return array<string, string> */
    private function leaveVariables(): array
    {
        return [
            'type_conge' => 'Type de congé',
            'date_depart' => 'Date de départ',
            'date_retour' => 'Date de retour',
            'jours_demandes' => 'Jours demandés',
            'motif_conge' => 'Motif du congé',
        ];
    }
}
