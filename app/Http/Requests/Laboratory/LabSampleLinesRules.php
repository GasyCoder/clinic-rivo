<?php

namespace App\Http\Requests\Laboratory;

/** ADR-214 — les lignes de prélèvement, communes à la réception et à l'ajout d'un tube. */
final class LabSampleLinesRules
{
    /** @return array<string, mixed> */
    public static function rules(bool $required): array
    {
        return [
            'samples' => [$required ? 'required' : 'sometimes', 'array', 'max:20'],
            'samples.*.sample_type_uuid' => ['required', 'uuid'],
            'samples.*.tube_type_uuid' => ['nullable', 'uuid'],
            'samples.*.quantity' => ['nullable', 'integer', 'min:1', 'max:10'],
            'samples.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'samples.required' => 'Ajoutez au moins un prélèvement.',
            'samples.*.sample_type_uuid.required' => 'Choisissez le type de prélèvement.',
            'samples.*.quantity.min' => 'De 1 à 10 tubes par ligne.',
            'samples.*.quantity.max' => 'De 1 à 10 tubes par ligne.',
        ];
    }
}
