<?php

namespace App\Actions\Episode;

use App\Models\EpisodeMutualCoverage;
use App\Models\EpisodeMutualCoverageAttachment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class StoreEpisodeMutualCoverageAttachmentsAction
{
    public const MAX_FILES = 5;

    /**
     * @param  array<int, UploadedFile>  $files
     * @return Collection<int, EpisodeMutualCoverageAttachment>
     */
    public function execute(EpisodeMutualCoverage $coverage, array $files, User $actor): Collection
    {
        if ($actor->cannot('patient_coverage_documents.create')) {
            throw new AuthorizationException('Vous ne pouvez pas ajouter ces pièces mutuelle.');
        }

        validator(['files' => $files], [
            'files' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],
            'files.*' => ['bail', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ])->validate();

        $storedPaths = [];

        try {
            return DB::transaction(function () use ($coverage, $files, $actor, &$storedPaths) {
                $coverage = EpisodeMutualCoverage::query()->lockForUpdate()->findOrFail($coverage->getKey());

                if ($coverage->attachments()->count() + count($files) > self::MAX_FILES) {
                    throw ValidationException::withMessages([
                        'files' => 'Une couverture mutuelle ne peut contenir que cinq pièces au maximum.',
                    ]);
                }

                return collect($files)->map(function (UploadedFile $file) use ($coverage, $actor, &$storedPaths) {
                    $path = $file->store(
                        "episode-mutual-coverages/{$coverage->uuid}/".now()->format('Y/m'),
                        'local',
                    );

                    if (! $path) {
                        throw ValidationException::withMessages([
                            'files' => 'Une pièce mutuelle n’a pas pu être enregistrée. Réessayez.',
                        ]);
                    }

                    $storedPaths[] = $path;

                    return $coverage->attachments()->create([
                        'path' => $path,
                        'original_name' => $this->safeOriginalName($file),
                        'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                        'size' => $file->getSize(),
                        'uploaded_by' => $actor->getKey(),
                    ]);
                });
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPaths);

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
