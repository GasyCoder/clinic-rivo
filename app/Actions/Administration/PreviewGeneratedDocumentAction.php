<?php

namespace App\Actions\Administration;

use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\LeaveRequest;
use App\Services\Administration\DocumentVariableResolver;

class PreviewGeneratedDocumentAction
{
    public function __construct(private readonly DocumentVariableResolver $resolver) {}

    /**
     * @param  array<string, string>  $manualVariables
     * @return array<string, mixed>
     */
    public function execute(
        DocumentTemplate $template,
        Employee $employee,
        ?EmploymentContract $contract,
        ?LeaveRequest $leave,
        array $manualVariables,
    ): array {
        $resolution = $this->resolver->resolve($template, $employee, $contract, $leave, $manualVariables);

        return [
            'resolved_variables' => $resolution['resolved'],
            'missing_variables' => $resolution['missing'],
            'rendered_html' => $this->resolver->render($template->content_html, $resolution['replacements']),
        ];
    }
}
