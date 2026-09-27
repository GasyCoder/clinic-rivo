<?php

namespace App\Enums;

/**
 * Which existing entities a document canevas is allowed to pull auto-resolved
 * variables from. Unlike DocumentTemplate::document_type (a free label so a
 * new document *category* never requires a deploy), this stays a fixed enum:
 * adding a new data source genuinely requires new resolver code (a model to
 * read from), so there is nothing to gain from making it configurable.
 */
enum DocumentDataContext: string
{
    case EmployeeOnly = 'EMPLOYEE_ONLY';
    case EmployeeAndContract = 'EMPLOYEE_AND_CONTRACT';
    case EmployeeAndLeave = 'EMPLOYEE_AND_LEAVE';

    public function label(): string
    {
        return match ($this) {
            self::EmployeeOnly => 'Personnel uniquement',
            self::EmployeeAndContract => 'Personnel + contrat de travail',
            self::EmployeeAndLeave => 'Personnel + demande de congé',
        };
    }
}
