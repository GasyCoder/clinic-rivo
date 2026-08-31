<?php

namespace App\Actions\Administration;

use App\Enums\HrReferenceType;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\HrDocument;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Administration\HrReferenceResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class StoreHrDocumentAction
{
    public function __construct(private readonly HrReferenceResolver $references) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, User $actor): HrDocument
    {
        Gate::forUser($actor)->authorize('create', HrDocument::class);

        /** @var UploadedFile $file */
        $file = $data['file'];
        $path = $file->store('human-resources/'.now()->format('Y/m'), 'local');

        if ($path === false) {
            throw ValidationException::withMessages([
                'file' => 'Le document n’a pas pu être enregistré. Veuillez réessayer.',
            ]);
        }

        try {
            $employee = Employee::query()->where('uuid', $data['employee_uuid'])->firstOrFail();
            $contract = ! empty($data['employment_contract_uuid'])
                ? EmploymentContract::query()->where('uuid', $data['employment_contract_uuid'])->firstOrFail()
                : null;
            $leave = ! empty($data['leave_request_uuid'])
                ? LeaveRequest::query()->where('uuid', $data['leave_request_uuid'])->firstOrFail()
                : null;
            $attestationType = $this->references->resolve(
                $data['attestation_type_uuid'] ?? null,
                HrReferenceType::AttestationType,
                'attestation_type_uuid',
            );

            return HrDocument::query()->create([
                'employee_id' => $employee->getKey(),
                'employment_contract_id' => $contract?->getKey(),
                'leave_request_id' => $leave?->getKey(),
                'attestation_type_id' => $attestationType?->getKey(),
                'category' => $data['category'],
                'title' => $data['title'],
                'original_name' => $this->safeOriginalName($file),
                'path' => $path,
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize(),
                'issued_on' => $data['issued_on'] ?? null,
                'notes' => $data['notes'] ?? null,
                'uploaded_by' => $actor->getKey(),
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    private function safeOriginalName(UploadedFile $file): string
    {
        $name = basename($file->getClientOriginalName());
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\]/u', '_', $name) ?: 'document';

        return Str::limit($name, 255, '');
    }
}
