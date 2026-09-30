<?php

namespace App\Actions\Bonus;

use App\Models\AdvantageArticle;
use App\Models\CatalogItem;
use App\Models\User;
use App\Support\Authorization\RemoteActorAttribution;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Créer ou corriger un article d'avantage à l'acte : son nom, ce qu'il compte, son prix
 * unitaire et les actes du catalogue qu'il regroupe. Corriger un article ne réécrit aucun
 * avantage déjà validé (lignes figées). Deux articles ne portent jamais le même nom,
 * archives comprises. Mêmes droits que les catégories de bonus (même module).
 */
class SaveAdvantageArticleAction
{
    /** @param array{name: string, source: string, unit_price: string|float, description?: ?string, catalog_item_uuids: list<string>} $data */
    public function execute(?AdvantageArticle $article, array $data, User $actor): AdvantageArticle
    {
        $permission = $article ? 'bonus_categories.update' : 'bonus_categories.create';

        if ($actor->cannot($permission)) {
            throw new AuthorizationException('Vous ne pouvez pas '.($article ? 'modifier' : 'créer').' un article d’avantage.');
        }

        return DB::transaction(function () use ($article, $data, $actor): AdvantageArticle {
            if ($article) {
                $article = AdvantageArticle::query()->lockForUpdate()->findOrFail($article->getKey());
            }

            $article ??= new AdvantageArticle(['created_by' => $actor->getKey(), ...RemoteActorAttribution::fields('created', $actor)]);
            $article->fill([
                'name' => $data['name'],
                'source' => $data['source'],
                'unit_price' => $data['unit_price'],
                'description' => $data['description'] ?? null,
            ]);

            $duplicate = AdvantageArticle::withTrashed()
                ->where('normalized_name', AdvantageArticle::normalize($article->name))
                ->when($article->exists, fn ($query) => $query->whereKeyNot($article->getKey()))
                ->first();

            if ($duplicate) {
                throw ValidationException::withMessages(['name' => $duplicate->trashed()
                    ? "Un article archivé s’appelle déjà « {$duplicate->name} » : restaurez-le."
                    : "Un article s’appelle déjà « {$duplicate->name} »."]);
            }

            $uuids = array_values(array_unique($data['catalog_item_uuids']));
            $items = CatalogItem::query()->whereIn('uuid', $uuids)->pluck('id');

            if ($items->count() !== count($uuids)) {
                throw ValidationException::withMessages(['catalog_item_uuids' => 'Un acte choisi n’existe plus dans le catalogue.']);
            }

            $article->save();
            $article->catalogItems()->sync($items->all());

            return $article;
        });
    }

    public function archive(AdvantageArticle $article, string $reason, User $actor): void
    {
        if ($actor->cannot('bonus_categories.archive')) {
            throw new AuthorizationException('Vous ne pouvez pas archiver un article d’avantage.');
        }

        $article->delete_reason = $reason;
        $article->delete();
    }

    public function restore(AdvantageArticle $article, User $actor): void
    {
        if ($actor->cannot('bonus_categories.restore')) {
            throw new AuthorizationException('Vous ne pouvez pas restaurer un article d’avantage.');
        }

        $duplicate = AdvantageArticle::query()->where('normalized_name', $article->normalized_name)->whereKeyNot($article->getKey())->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['name' => "Un article en service s’appelle déjà « {$article->name} »."]);
        }

        $article->restore();
    }
}
