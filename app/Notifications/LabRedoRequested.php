<?php

namespace App\Notifications;

use App\Models\LabRequest;

/**
 * ADR-216, amendement du 2026-09-29 — au technicien qui avait envoyé un résultat :
 * quelqu'un d'autre (un médecin, un collègue) le renvoie à refaire, avec son motif.
 */
class LabRedoRequested extends InboxNotification
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
            'kind' => 'laboratory.results.redo_requested',
            'category' => 'laboratory',
            'title' => 'Analyse à refaire — '.$name,
            'body' => "« {$this->analysis} » est renvoyée à refaire par {$this->by} : {$this->reason}.",
            'url' => '/laboratory/requests/'.$this->request->uuid,
            'icon' => 'rotate-ccw',
            'tone' => 'warning',
            'meta' => ['lab_request_uuid' => $this->request->uuid],
        ];
    }
}
