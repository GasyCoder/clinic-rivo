<?php

namespace App\Actions\Administration;

use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\LeaveRequest;
use App\Services\Administration\DocumentFormDataResolver;

class PreviewGeneratedDocumentAction
{
    public function __construct(private readonly DocumentFormDataResolver $resolver) {}

    /**
     * @param  array<string, string>  $formData
     * @return array<string, mixed>
     */
    public function execute(
        DocumentTemplate $template,
        Employee $employee,
        ?EmploymentContract $contract,
        ?LeaveRequest $leave,
        array $formData,
    ): array {
        $this->resolver->assertContext($template->data_context, $contract, $leave);
        $resolution = $this->resolver->resolve($template->data_context, $employee, $contract, $leave, $formData);
        $pageOneHtml = $this->resolver->renderPageOne($template->data_context, $resolution['values']);

        return [
            'form_values' => $resolution['values'],
            'missing_required_fields' => $resolution['missing_required'],
            'rendered_html' => $pageOneHtml.DocumentFormDataResolver::PAGE_BREAK_HTML.$template->content_html,
        ];
    }
}
