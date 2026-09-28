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
 * ADR-214 — réceptionner une demande au laboratoire (CDC §14,
 * `laboratory.orders.receive`) : le règlement est contrôlé, la demande reçoit
 * son numéro de laboratoire, et ses prélèvements peuvent être enregistrés dans
 * le même geste.
 *
 * Refusée tant qu'une analyse reste à régler à la Caisse, sauf urgence ou
 * patient hospitalisé — l'exception est figée sur la demande et dans l'audit.
 * Le Laboratoire n'encaisse rien : il renvoie à la Caisse (ADR-012, ADR-014).
 */
class ReceiveLabRequestAction
{
    public function __construct(
        private readonly LabPaymentClearance $clearance,
        private readonly LabNumberGenerator $numbers,
        private readonly RecordLabSamplesAction $samples,
        private readonly Auditor $auditor,
    ) {}

    /** @param  array<int, array<string, mixed>>  $samples */
    public function execute(LabRequest $request, array $samples, User $actor): LabRequest
    {
        if ($actor->cannot('laboratory_orders.receive')) {
            throw new AuthorizationException('Réceptionner une demande demande le droit « laboratory_orders.receive ».');
        }
        if ($samples !== [] && $actor->cannot('laboratory_samples.create')) {
            throw new AuthorizationException('Enregistrer un prélèvement demande le droit « laboratory_samples.create ».');
        }

        return DB::transaction(function () use ($request, $samples, $actor): LabRequest {
            $locked = LabRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($locked->cancelled_at !== null) {
                throw ValidationException::withMessages(['request' => 'Cette demande a été retirée par le prescripteur : elle ne se réceptionne plus.']);
            }
            if ($locked->received_at !== null) {
                $locked->loadMissing('receivedBy:id,name');
                throw ValidationException::withMessages(['request' => 'Cette demande est déjà réceptionnée'
                    .($locked->receivedBy ? " par {$locked->receivedBy->name}" : '')
                    .', le '.$locked->received_at->timezone(config('app.timezone'))->format('d/m/Y à H:i').'.']);
            }

            $clearance = $this->clearance->for($locked);
            if (! $clearance['cleared']) {
                throw ValidationException::withMessages(['request' => $clearance['summary']]);
            }

            $locked->update([
                'lab_number' => $this->numbers->next(),
                'received_at' => now(),
                'received_by' => $actor->getKey(),
                'payment_exemption' => $clearance['exemption'],
            ]);

            if ($samples !== []) {
                $this->samples->create($locked, $samples, $actor);
            }

            $this->auditor->record(
                'laboratory.request.receive',
                $locked,
                [
                    'lab_number' => $locked->lab_number,
                    'payment_exemption' => $clearance['exemption'],
                    'unbilled' => collect($clearance['lines'])->where('needs_regularization', true)->pluck('name')->values()->all(),
                    'samples' => count($samples),
                ],
                module: 'laboratory',
                actor: $actor,
            );

            return $locked->fresh();
        });
    }
}
