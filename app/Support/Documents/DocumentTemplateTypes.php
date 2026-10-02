<?php

namespace App\Support\Documents;

use App\Enums\DocumentDataContext;
use App\Models\DocumentTemplate;
use App\Models\EmploymentContract;
use App\Models\HrReferenceValue;
use App\Models\LeaveRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * ADR-244 — un modèle de contrat ou de congé vise des types précis (CDI, CDD… ;
 * maladie, maternité…), désignés par le code stable du référentiel RH du site.
 * Un modèle sans type est général : il convient à tous.
 *
 * Pour un contrat ou un congé donné, les modèles qui visent son type passent
 * d'abord ; s'il n'y en a aucun, les modèles généraux. Jamais un modèle d'un
 * autre type.
 */
class DocumentTemplateTypes
{
    /** Les types qu'un modèle de ce contexte peut viser, du référentiel du site (archivés exclus). */
    public static function options(DocumentDataContext $context): array
    {
        $type = $context->referenceType();
        if ($type === null) {
            return [];
        }

        return HrReferenceValue::query()->ofType($type)
            ->orderBy('position')->orderBy('label')->get()
            ->map(fn (HrReferenceValue $value) => [
                'code' => $value->code,
                'label' => $value->label,
                'active' => (bool) $value->active,
            ])->values()->all();
    }

    /** @return array<string, list<array{code: string, label: string, active: bool}>> par contexte */
    public static function allOptions(): array
    {
        return collect(DocumentDataContext::cases())
            ->filter(fn (DocumentDataContext $context) => $context->referenceType() !== null)
            ->mapWithKeys(fn (DocumentDataContext $context) => [$context->value => self::options($context)])
            ->all();
    }

    /**
     * Les codes à enregistrer : en majuscules, sans doublon, refusés s'ils ne
     * désignent aucun type du site. `null` pour un modèle général ou un contexte
     * sans type.
     *
     * @param  array<int, mixed>|null  $codes
     * @return list<string>|null
     */
    public static function normalize(?array $codes, DocumentDataContext $context): ?array
    {
        $type = $context->referenceType();
        $codes = collect($codes ?? [])->map(fn ($code) => mb_strtoupper(trim((string) $code)))
            ->filter()->unique()->values();

        if ($type === null || $codes->isEmpty()) {
            return null;
        }

        $known = HrReferenceValue::withTrashed()->ofType($type)->whereIn('code', $codes)->pluck('code');
        $unknown = $codes->diff($known);
        if ($unknown->isNotEmpty()) {
            throw ValidationException::withMessages([
                'applies_to' => 'Type inconnu sur ce site : '.$unknown->implode(', ').'.',
            ]);
        }

        return $codes->sort()->values()->all();
    }

    /** Les libellés des types visés, pour l'écran. */
    public static function labels(DocumentTemplate $template): array
    {
        $type = $template->data_context->referenceType();
        if ($type === null || $template->appliesTo() === []) {
            return [];
        }

        $labels = HrReferenceValue::withTrashed()->ofType($type)->whereIn('code', $template->appliesTo())->pluck('label', 'code');

        return collect($template->appliesTo())->map(fn (string $code) => $labels[$code] ?? $code)->values()->all();
    }

    public static function contractCode(?EmploymentContract $contract): ?string
    {
        return $contract?->contractType()->withTrashed()->value('code');
    }

    public static function leaveCode(?LeaveRequest $leave): ?string
    {
        return $leave?->leaveType()->withTrashed()->value('code');
    }

    /**
     * Parmi des modèles d'un même contexte : ceux qui visent ce type, sinon les
     * généraux.
     *
     * @template T of DocumentTemplate|array
     *
     * @param  Collection<int, T>  $templates
     * @return Collection<int, T>
     */
    public static function matching(Collection $templates, ?string $typeCode): Collection
    {
        $codesOf = fn ($template): array => $template instanceof DocumentTemplate
            ? $template->appliesTo()
            : array_values($template['applies_to'] ?? []);

        $specific = $typeCode === null ? collect() : $templates->filter(fn ($template) => in_array($typeCode, $codesOf($template), true));

        return ($specific->isNotEmpty() ? $specific : $templates->filter(fn ($template) => $codesOf($template) === []))->values();
    }
}
