<?php

namespace App\Actions\Reception;

use App\Enums\VisitorCategory;
use App\Models\Patient;
use App\Models\VisitorVisit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class RegisterVisitorVisitAction
{
    /**
     * @param  array{full_name: string, phone: string, category: string, organization?: ?string, professional_attachments?: array<int, UploadedFile>, patient_uuid?: ?string, reason: string}  $data
     */
    public function execute(array $data): VisitorVisit
    {
        $category = VisitorCategory::from($data['category']);
        $files = $category === VisitorCategory::Professional
            ? ($data['professional_attachments'] ?? [])
            : [];
        $storedFiles = [];

        try {
            foreach ($files as $file) {
                $path = $file->store('visitor-visits/professional/'.now()->format('Y/m'), 'local');

                if (! $path) {
                    throw ValidationException::withMessages([
                        'professional_attachments' => 'Une pièce jointe n’a pas pu être enregistrée. Réessayez.',
                    ]);
                }

                $storedFiles[] = [
                    'path' => $path,
                    'original_name' => $this->safeOriginalName($file),
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'size' => $file->getSize(),
                ];
            }

            return DB::transaction(function () use ($data, $category, $storedFiles) {
                $patientId = $category === VisitorCategory::PatientOrFamilyVisit && ! empty($data['patient_uuid'])
                    ? Patient::query()->where('uuid', $data['patient_uuid'])->value('id')
                    : null;

                $visitor = VisitorVisit::create([
                    'full_name' => trim($data['full_name']),
                    'phone' => trim($data['phone']),
                    'category' => $category,
                    'organization' => $category === VisitorCategory::Professional
                        ? trim((string) $data['organization'])
                        : null,
                    'patient_id' => $patientId,
                    'reason' => trim($data['reason']),
                    'checked_in_at' => now(),
                    'created_by' => Auth::id(),
                ]);

                foreach ($storedFiles as $storedFile) {
                    $visitor->attachments()->create($storedFile);
                }

                return $visitor->load('attachments');
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete(array_column($storedFiles, 'path'));

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
