<?php

namespace App\Actions\Pharmacy;

use App\Models\ProductSynonym;
use App\Services\Catalog\CatalogActor;
use App\Services\Pharmacy\ProductSynonyms;
use App\Support\ProductLabel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * ADR-241 — ajouter ou retirer une abréviation du dictionnaire du site.
 * Rien n'est recalculé en base : la règle relit le dictionnaire à chaque
 * comparaison.
 */
class SaveProductSynonymAction
{
    public function __construct(private readonly ProductSynonyms $synonyms) {}

    public function execute(string $term, string $canonical, ?string $note, CatalogActor $actor): ProductSynonym
    {
        $this->authorize($actor);

        $term = ProductSynonyms::term($term);
        $canonical = ProductSynonyms::canonical($canonical);

        if ($term === '' || mb_strlen($term) > 40) {
            throw ValidationException::withMessages(['term' => 'L’abréviation est un seul mot, de 40 caractères au plus.']);
        }

        if ($canonical === '' || mb_strlen($canonical) > 60) {
            throw ValidationException::withMessages(['canonical' => 'Indiquez ce que l’abréviation veut dire (60 caractères au plus).']);
        }

        if ($canonical === $term) {
            throw ValidationException::withMessages(['canonical' => 'Une abréviation ne se lit pas comme elle-même.']);
        }

        if (preg_match('/^\d+$/', $term)) {
            throw ValidationException::withMessages(['term' => 'Un nombre n’est pas une abréviation : les nombres font le produit.']);
        }

        $synonym = ProductSynonym::query()->firstOrNew(['term' => $term]);
        $synonym->fill([
            'canonical' => $canonical,
            'note' => filled($note) ? mb_substr(trim((string) $note), 0, 255) : null,
        ]);

        if (! $synonym->exists) {
            $synonym->fill(['created_by' => $actor->localUserId(), ...$actor->externalAttribution('created')]);
        }

        $synonym->save();
        $this->synonyms->forget();

        return $synonym;
    }

    public function delete(ProductSynonym $synonym, CatalogActor $actor): void
    {
        $this->authorize($actor);
        $synonym->delete();
        $this->synonyms->forget();
    }

    /**
     * Le dictionnaire tel que la règle le lit : celui livré avec RIVO, puis
     * celui du site, qui l'emporte.
     *
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'built_in' => collect(ProductLabel::BUILT_IN_SYNONYMS)
                ->map(fn (string $canonical, string $term) => ['term' => $term, 'canonical' => $canonical])
                ->values()
                ->all(),
            'site' => ProductSynonym::query()->orderBy('term')->get()->map(fn (ProductSynonym $synonym) => [
                'uuid' => $synonym->uuid,
                'term' => $synonym->term,
                'canonical' => $synonym->canonical,
                'note' => $synonym->note,
                'author' => $synonym->creator?->name ?? $synonym->external_created_by_name,
                'created_at' => $synonym->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    private function authorize(CatalogActor $actor): void
    {
        if ($actor->cannot(DecideSupplierProductEquivalenceAction::PERMISSION)) {
            throw new AuthorizationException('Régler le dictionnaire demande le droit « '.DecideSupplierProductEquivalenceAction::PERMISSION.' ».');
        }
    }
}
