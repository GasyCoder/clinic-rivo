<?php

namespace App\Notifications;

use App\Models\LabRequest;

/**
 * ADR-216 — au médecin destinataire : le laboratoire lui envoie des résultats.
 *
 * Elle ne porte aucune valeur — seulement le patient, les analyses et le lien
 * vers la page des résultats : une notification se relit hors contexte.
 */
class LabResultsAddressed extends InboxNotification
{
    /** @param  list<string>  $analyses */
    public function __construct(
        public readonly LabRequest $request,
        public readonly array $analyses,
        public readonly string $sender,
        public readonly bool $corrected = false,
    ) {}

    public function payload(): array
    {
        $patient = $this->request->episode?->patient;
        $name = $patient ? trim($patient->last_name.' '.$patient->first_name) : 'un patient';
        $list = implode(', ', $this->analyses);

        return [
            'kind' => $this->corrected ? 'laboratory.results.corrected' : 'laboratory.results.sent',
            'category' => 'laboratory',
            'title' => ($this->corrected ? 'Résultat corrigé — ' : 'Résultats d’analyses — ').$name,
            'body' => $list.' · envoyé par '.$this->sender
                .($this->request->lab_number ? ' · n° '.$this->request->lab_number : '')
                .($this->corrected ? ' · le laboratoire a corrigé un résultat déjà envoyé.' : ''),
            'url' => '/resultats-analyses/'.$this->request->uuid,
            'icon' => 'flask-conical',
            'tone' => $this->corrected ? 'warning' : 'info',
            'meta' => ['lab_request_uuid' => $this->request->uuid],
        ];
    }
}
