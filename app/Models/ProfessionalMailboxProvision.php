<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-190 — ce que le portail a réellement créé chez l'hébergeur, pour la
 * demande d'un site.
 *
 * Si la boîte est créée mais que le site ne confirme pas (panne, délai),
 * cette ligne dit au portail de ne pas la recréer : il ne reste qu'à
 * confirmer au site. Elle ne contient jamais de mot de passe.
 */
#[Fillable(['site_code', 'mailbox_uuid', 'address', 'created_by', 'host_created_at', 'site_confirmed_at', 'host_suspended_at'])]
class ProfessionalMailboxProvision extends Model
{
    use HasUuid;

    protected function casts(): array
    {
        return ['host_created_at' => 'datetime', 'site_confirmed_at' => 'datetime', 'host_suspended_at' => 'datetime'];
    }
}
