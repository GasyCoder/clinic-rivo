<?php

namespace App\Actions\Maternity;

use App\Actions\Care\RequestCareConsumablesAction;
use App\Models\CatalogItem;
use App\Models\EpisodeOrientation;
use App\Models\MaternityProcedure;
use App\Models\MaternityRecordDraft;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Un panier d'actes enregistré d'un seul geste (ADR-138).
 *
 * Chaque ligne passe par l'Action à l'unité, inchangée : mêmes règles (prise en
 * charge active, césarienne refusée, « Autres » à préciser). Si une seule
 * ligne est refusée, **rien** n'est enregistré, et le refus nomme la ligne pour
 * que la sage-femme la corrige dans son panier — même schéma que la livraison
 * de stock de la Pharmacie (ADR-098).
 */
class RecordMaternityProceduresAction
{
    public function __construct(
        private readonly RecordMaternityProcedureAction $procedure,
        private readonly RequestCareConsumablesAction $consumables,
    ) {}

    /**
     * Le matériel utilisé part dans le **même geste** (ADR-142) : un DIU et son
     * geste sont un seul acte pour la sage-femme, et un second formulaire ferait
     * perdre le matériel dès qu'elle valide sans l'avoir envoyé.
     *
     * @param  array<int, array{catalog_item_uuid: string, quantity: mixed, notes?: ?string}>  $lines
     * @param  array<int, array{medicine_uuid: string, quantity: mixed}>  $consumables
     * @return list<MaternityProcedure>
     */
    public function execute(EpisodeOrientation $orientation, array $lines, User $actor, array $consumables = [], ?string $consumableNotes = null): array
    {
        return DB::transaction(function () use ($orientation, $lines, $actor, $consumables, $consumableNotes): array {
            $recorded = [];

            foreach (array_values($lines) as $index => $line) {
                try {
                    $recorded[] = $this->procedure->execute($orientation, $line, $actor);
                } catch (ValidationException $exception) {
                    $name = CatalogItem::query()->where('uuid', $line['catalog_item_uuid'] ?? null)->value('name') ?? 'Acte';

                    throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(
                        fn (array $messages, string $field) => ["procedures.{$index}.{$field}" => sprintf('%s : %s', $name, $messages[0])],
                    )->all());
                }
            }

            if ($consumables !== []) {
                try {
                    $this->consumables->execute(
                        $orientation->fresh(['episode.maternityRecord']),
                        ['lines' => array_values($consumables), 'notes' => $consumableNotes],
                        $actor,
                    );
                } catch (ValidationException $exception) {
                    // Une erreur de matériel se lit sur le matériel, pas sur un champ des Soins.
                    throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(
                        fn (array $messages, string $field) => [str_starts_with($field, 'lines') ? 'consumables' : $field => $messages[0]],
                    )->all());
                }
            }

            // Le panier est devenu de vrais actes : son brouillon ne doit jamais
            // ressortir et remettre dans le panier ce qui vient d'être enregistré.
            MaternityRecordDraft::forgetSection($orientation->getKey(), $actor->getKey(), 'basket');

            return $recorded;
        });
    }
}
