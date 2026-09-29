<?php

namespace App\Notifications;

use App\Models\LabRequest;

/**
 * ADR-216 — au médecin destinataire : un résultat qu'il a reçu est repris par le
 * laboratoire pour être refait. Il a pu le lire et agir dessus : il doit le savoir.
 */
class LabResultReturned extends InboxNotification
{
    public function __construct(
        public readonly LabRequest $request,
        public readonly string $analysis,
        public readonly string $reason,
        public readonly string $by,
    ) {}

    public function payload(): array
    {
        $patient = $this->request->episode?->patient;
        $name = $patient ? trim($patient->last_name.' '.$patient->first_name) : 'un patient';

        return [
            'kind' => 'laboratory.results.returned',
            'category' => 'laboratory',
            'title' => 'Résultat repris par le laboratoire — '.$name,
            'body' => "« {$this->analysis} » est à refaire ({$this->reason}). Ne vous fiez pas à la valeur reçue : un nouvel envoi suivra. Repris par {$this->by}.",
            'url' => '/resultats-analyses/'.$this->request->uuid,
            'icon' => 'flask-conical',
            'tone' => 'danger',
            'meta' => ['lab_request_uuid' => $this->request->uuid],
        ];
    }
}
