<?php

namespace App\Services\Medicine;

use App\Models\DiagnosticCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DiagnosticCatalogManager
{
    /** @param array{code?: ?string, name: string, description?: ?string, category?: ?string} $data */
    public function create(array $data): DiagnosticCatalog
    {
        return DB::transaction(function () use ($data): DiagnosticCatalog {
            $values = $this->normalize($data);
            $this->ensureUnique($values['name'], $values['code']);

            return DiagnosticCatalog::query()->create($values + ['is_active' => true]);
        });
    }

    /** @param array{code?: ?string, name: string, description?: ?string, category?: ?string} $data */
    public function update(DiagnosticCatalog $catalog, array $data): DiagnosticCatalog
    {
        return DB::transaction(function () use ($catalog, $data): DiagnosticCatalog {
            $catalog = DiagnosticCatalog::query()->whereKey($catalog->getKey())->lockForUpdate()->firstOrFail();
            $values = $this->normalize($data);
            $this->ensureUnique($values['name'], $values['code'], $catalog);
            $catalog->update($values);

            return $catalog->refresh();
        });
    }

    public function activate(DiagnosticCatalog $catalog): DiagnosticCatalog
    {
        $catalog->update(['is_active' => true]);

        return $catalog->refresh();
    }

    public function deactivate(DiagnosticCatalog $catalog): DiagnosticCatalog
    {
        $catalog->update(['is_active' => false]);

        return $catalog->refresh();
    }

    /** @param array{code?: ?string, name: string, description?: ?string, category?: ?string} $data */
    private function normalize(array $data): array
    {
        $nullable = fn (?string $value): ?string => ($value = str((string) $value)->squish()->toString()) === '' ? null : $value;

        return [
            'code' => ($code = $nullable($data['code'] ?? null)) ? mb_strtoupper($code) : null,
            'name' => str($data['name'])->squish()->toString(),
            'description' => $nullable($data['description'] ?? null),
            'category' => $nullable($data['category'] ?? null),
        ];
    }

    private function ensureUnique(string $name, ?string $code, ?DiagnosticCatalog $except = null): void
    {
        $byName = DiagnosticCatalog::query()
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->lockForUpdate()
            ->exists();

        if ($byName) {
            throw ValidationException::withMessages(['name' => 'Un diagnostic porte déjà ce libellé.']);
        }

        if ($code && DiagnosticCatalog::query()
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->where('code', $code)
            ->lockForUpdate()
            ->exists()) {
            throw ValidationException::withMessages(['code' => 'Ce code est déjà utilisé.']);
        }
    }
}
