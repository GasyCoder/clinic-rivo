<?php

namespace App\Actions\Administration;

use App\Enums\HrDocumentCategory;
use App\Enums\HrReferenceType;
use App\Enums\LeaveRequestStatus;
use App\Models\Employee;
use App\Models\HrDocument;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Administration\HrReferenceResolver;
use App\Services\Administration\LeaveBalanceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateLeaveRequestAction
{
    public function __construct(
        private readonly HrReferenceResolver $references,
        private readonly LeaveBalanceCalculator $calculator,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, User $actor): LeaveRequest
    {
        Gate::forUser($actor)->authorize('create', LeaveRequest::class);

        /** @var UploadedFile|null $justification */
        $justification = $data['justification'] ?? null;
        unset($data['justification']);
        $storedPath = $justification?->store('human-resources/leave-justifications/'.now()->format('Y/m'), 'local');

        if ($justification && $storedPath === false) {
            throw ValidationException::withMessages(['justification' => 'Le justificatif n’a pas pu être enregistré.']);
        }

        try {
            return DB::transaction(function () use ($data, $actor, $justification, $storedPath): LeaveRequest {
                $employee = Employee::query()->where('uuid', $data['employee_uuid'])->lockForUpdate()->firstOrFail();
                $interim = ! empty($data['interim_employee_uuid'])
                    ? Employee::query()->where('uuid', $data['interim_employee_uuid'])->firstOrFail()
                    : null;
                $leaveType = $this->references->resolve(
                    $data['leave_type_uuid'],
                    HrReferenceType::LeaveType,
                    'leave_type_uuid',
                );
                $preview = $this->calculator->preview(
                    $employee,
                    $leaveType,
                    CarbonImmutable::parse($data['starts_on'])->startOfDay(),
                    CarbonImmutable::parse($data['returns_on'])->startOfDay(),
                );
                if (! $preview['requires_approval']) {
                    $this->calculator->assertSufficientBalance($preview);
                }

                $leave = LeaveRequest::query()->create([
                    'employee_id' => $employee->getKey(),
                    'interim_employee_id' => $interim?->getKey(),
                    'leave_type_id' => $leaveType->getKey(),
                    'leave_address' => $data['leave_address'] ?? null,
                    'emergency_phone' => $data['emergency_phone'] ?? null,
                    'days_requested' => $preview['days_requested'],
                    'remaining_days_snapshot' => $preview['requires_approval']
                        ? $preview['balance_before']
                        : $preview['balance_after_request'],
                    'projected_remaining_days_snapshot' => $preview['projected_balance'],
                    'annual_quota_snapshot' => $preview['annual_quota_days'],
                    'day_count_method_snapshot' => $preview['day_count_method'],
                    'consumes_balance_snapshot' => $preview['consumes_annual_balance'],
                    'requires_approval_snapshot' => $preview['requires_approval'],
                    'reason' => $data['reason'],
                    'requested_on' => now()->toDateString(),
                    'starts_on' => $data['starts_on'],
                    'returns_on' => $data['returns_on'],
                ]);

                if ($justification && is_string($storedPath)) {
                    HrDocument::query()->create([
                        'employee_id' => $employee->getKey(),
                        'leave_request_id' => $leave->getKey(),
                        'category' => HrDocumentCategory::Leave,
                        'title' => 'Justificatif · '.$leaveType->label,
                        'original_name' => $this->safeOriginalName($justification),
                        'path' => $storedPath,
                        'mime_type' => $justification->getMimeType() ?: 'application/octet-stream',
                        'size' => $justification->getSize(),
                        'issued_on' => now()->toDateString(),
                        'uploaded_by' => $actor->getKey(),
                    ]);
                }

                if (! $preview['requires_approval']) {
                    $leave->decide(LeaveRequestStatus::Approved, 'Validation non requise par la règle du type de demande.');
                }

                return $leave->refresh()->load(['employee.department', 'employee.jobTitle', 'interimEmployee', 'leaveType', 'documents']);
            });
        } catch (Throwable $exception) {
            if (is_string($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }
    }

    private function safeOriginalName(UploadedFile $file): string
    {
        $name = basename($file->getClientOriginalName());
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\]/u', '_', $name) ?: 'justificatif';

        return Str::limit($name, 255, '');
    }
}
