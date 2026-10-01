<?php

namespace App\Actions\Laboratory;

use App\Models\LabRequest;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Laboratory\LabNumberGenerator;
use App\Services\Laboratory\LabPaymentClearance;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-214 — la prise en charge d'une demande au laboratoire (CDC §14) : elle
 * reçoit son numéro de laboratoire, et ses prélèvements peuvent être
 * enregistrés dans le même geste.
 *
 * ADR-217 — le règlement ne retient plus le technicien : « Traiter » prend la
 * demande en charge quel que soit l'état du paiement, qui reste affiché et
 * figé dans l'audit. Le Laboratoire n'encaisse toujours rien (ADR-012,
 * ADR-014) : l'encaissement reste à la Caisse.
 */
class ReceiveLabRequestAction
{
    public function __construct(
        private readonly LabPaymentClearance $clearance,
        private readonly LabNumberGenerator $numbers,
        private readonly RecordLabSamplesAction $samples,
        private readonly Auditor $auditor,
    ) {}

    /** Prendre en charge une demande : le droit de réceptionner, ou celui de saisir. */
    public static function canTakeUp(User $actor): bool
    {
        return $actor->can('laboratory_orders.receive') || $actor->can('laboratory_results.create');
    }

    /** @param  array<int, array<string, mixed>>  $samples */
    public function execute(LabRequest $request, array $samples, User $actor): LabRequest
    {
        $this->authorize($actor);
        if ($samples !== [] && $actor->cannot('laboratory_samples.create')) {
            throw new AuthorizationException('Enregistrer un prélèvement demande le droit « laboratory_samples.create ».');
        }

        return DB::transaction(function () use ($request, $samples, $actor): LabRequest {
            $locked = LabRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($locked->cancelled_at !== null) {
                throw ValidationException::withMessages(['request' => 'Cette demande a été retirée par le prescripteur : elle ne se traite plus.']);
            }
            if ($locked->received_at !== null) {
                $locked->loadMissing('receivedBy:id,name');
                throw ValidationException::withMessages(['request' => 'Cette demande est déjà prise en charge'
                    .($locked->receivedBy ? " par {$locked->receivedBy->name}" : '')
                    .', le '.$locked->received_at->timezone(config('app.timezone'))->format('d/m/Y à H:i').'.']);
            }

            $this->takeUp($locked, $actor, 'reception');

            if ($samples !== []) {
                $this->samples->create($locked, $samples, $actor);
            }

            return $locked->fresh();
        });
    }

    /** « Traiter » depuis la file : idempotent, une demande déjà prise en charge est rendue telle quelle. */
    public function start(LabRequest $request, User $actor): LabRequest
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($request, $actor): LabRequest {
            $locked = LabRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            $this->takeUp($locked, $actor, 'start');

            return $locked->fresh();
        });
    }

    /**
     * Sur une demande déjà verrouillée : lui donne son numéro si personne ne l'a
     * encore prise en charge. Sans effet sinon. Appelée aussi par la première
     * saisie d'un résultat — le technicien n'est jamais arrêté par une étape.
     */
    public function takeUp(LabRequest $locked, User $actor, string $via = 'entry'): void
    {
        if ($locked->cancelled_at !== null) {
            throw ValidationException::withMessages(['request' => 'Cette demande a été retirée par le prescripteur : elle ne se traite plus.']);
        }
        if ($locked->received_at !== null) {
            return;
        }

        $clearance = $this->clearance->for($locked);

        $locked->update([
            'lab_number' => $this->numbers->next(),
            'received_at' => now(),
            'received_by' => $actor->getKey(),
            'payment_exemption' => $clearance['exemption'],
        ]);

        $this->auditor->record(
            'laboratory.request.receive',
            $locked,
            [
                'lab_number' => $locked->lab_number,
                'via' => $via,
                'payment_exemption' => $clearance['exemption'],
                // Ce qui restait à régler au moment de la prise en charge : tracé, jamais bloquant.
                'unpaid' => collect($clearance['lines'])->where('blocking', true)->pluck('name')->values()->all(),
                'unbilled' => collect($clearance['lines'])->where('needs_regularization', true)->pluck('name')->values()->all(),
            ],
            module: 'laboratory',
            actor: $actor,
        );
    }

    private function authorize(User $actor): void
    {
        if (! self::canTakeUp($actor)) {
            throw new AuthorizationException('Prendre en charge une demande demande le droit « laboratory_results.create » ou « laboratory_orders.receive ».');
        }
    }
}
