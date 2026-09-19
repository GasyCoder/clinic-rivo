<?php

namespace App\Support;

use App\Models\CatalogItem;
use App\Models\Consultation;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Une seule demande active par examen et par consultation.
 *
 * Les deux actions de création n'en vérifiaient aucune : deux clics sur
 * « Envoyer la demande » produisaient deux ECG réellement distincts, que le
 * service voyait comme deux examens à réaliser. Le compteur affichant « 2 »
 * ne mentait donc pas — c'était la base qui portait le doublon.
 *
 * « Active » veut dire : ni annulée, ni déjà résultée. Une demande annulée
 * se redemande légitimement (le médecin revient sur sa décision), et une
 * demande déjà résultée aussi (un contrôle à distance est un examen neuf,
 * pas un doublon).
 *
 * La règle vit ici, et non recopiée dans chaque action, pour la même raison
 * que les bornes de constantes (`VitalSignRules`) : deux implémentations
 * finiraient par diverger, et l'une accepterait ce que l'autre refuse.
 *
 * Elle est appliquée **dans la transaction** des actions, qui verrouillent
 * déjà la consultation : deux envois simultanés sont donc sérialisés par la
 * base, et le second lit le résultat du premier. L'interface n'est jamais la
 * seule protection.
 */
class ParaclinicalRequestGuard
{
    /**
     * @param  Collection<int, CatalogItem>  $requested  indexée par uuid
     *
     * @throws ValidationException
     */
    public static function ensureNoActiveDuplicate(
        Consultation $consultation,
        Collection $requested,
        string $relation,
        string $errorKey,
    ): void {
        $alreadyActive = $consultation->{$relation}()
            ->whereNull('cancelled_at')
            ->with('items')
            ->get()
            ->flatMap(fn ($request) => $request->items)
            // Une ligne déjà résultée n'occupe plus la place : la redemander
            // est un nouvel examen, pas un doublon.
            ->filter(fn ($item) => $item->resulted_at === null)
            ->pluck('catalog_item_id')
            ->unique();

        $duplicates = $requested
            ->filter(fn ($item) => $alreadyActive->contains($item->getKey()))
            ->map(fn ($item) => $item->name)
            ->values();

        if ($duplicates->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            $errorKey => $duplicates->count() === 1
                ? sprintf(
                    '« %s » a déjà été demandé pour cette consultation et reste en attente. Annulez la demande existante avant d’en créer une autre.',
                    $duplicates->first(),
                )
                : sprintf(
                    'Ces examens ont déjà été demandés pour cette consultation et restent en attente : %s.',
                    $duplicates->join(', ', ' et '),
                ),
        ]);
    }
}
