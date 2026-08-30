<?php

namespace App\Services\Administration;

use App\Models\AddressEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddressEntryManager
{
    public function create(string $label, string $errorField = 'label'): AddressEntry
    {
        $label = $this->validatedLabel($label, $errorField);

        return DB::transaction(function () use ($label, $errorField): AddressEntry {
            $existing = AddressEntry::withTrashed()
                ->where('normalized_label', AddressEntry::normalize($label))
                ->lockForUpdate()
                ->first();

            if ($existing?->trashed()) {
                throw ValidationException::withMessages([
                    $errorField => 'Cette adresse est archivée. Restaurez-la au lieu de créer un doublon.',
                ]);
            }

            if ($existing) {
                return $existing;
            }

            return AddressEntry::query()->create(['label' => $label, 'active' => true]);
        });
    }

    public function update(AddressEntry $entry, string $label): AddressEntry
    {
        $label = $this->validatedLabel($label, 'label');

        return DB::transaction(function () use ($entry, $label): AddressEntry {
            $normalized = AddressEntry::normalize($label);
            $duplicate = AddressEntry::withTrashed()
                ->where('normalized_label', $normalized)
                ->whereKeyNot($entry->getKey())
                ->lockForUpdate()
                ->first();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'label' => $duplicate->trashed()
                        ? 'Une adresse identique est archivée. Restaurez-la au lieu de créer un doublon.'
                        : 'Cette adresse existe déjà dans le référentiel.',
                ]);
            }

            $entry->update(['label' => $label]);

            return $entry->refresh();
        });
    }

    public function archive(AddressEntry $entry, string $reason): void
    {
        $reason = str($reason)->squish()->toString();

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'reason' => 'Le motif d’archivage doit contenir entre 5 et 500 caractères.',
            ]);
        }

        $entry->active = false;
        $entry->delete_reason = $reason;
        $entry->save();
        $entry->delete();
    }

    public function restore(AddressEntry $entry): AddressEntry
    {
        $conflict = AddressEntry::query()
            ->where('normalized_label', $entry->normalized_label)
            ->whereKeyNot($entry->getKey())
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'address' => 'Une adresse active identique existe déjà. La restauration est impossible.',
            ]);
        }

        $entry->active = true;
        $entry->restore();
        $entry->save();

        return $entry->refresh();
    }

    private function validatedLabel(string $label, string $errorField): string
    {
        $label = str($label)->squish()->toString();

        if ($label === '' || mb_strlen($label) > 255) {
            throw ValidationException::withMessages([
                $errorField => 'L’adresse doit contenir entre 1 et 255 caractères.',
            ]);
        }

        return $label;
    }
}
