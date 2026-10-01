<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-222 — une question posée à l'assistant : qui, quand, quel fournisseur, et les
 * tokens qu'il a comptés. Ni la question ni la réponse : elles vivent dans les
 * conversations du SDK, dans la même base. Une question qui a échoué ou qu'on a
 * arrêtée est comptée aussi (avec sa catégorie d'erreur, jamais son message) : c'est une tentative.
 */
#[Fillable(['user_id', 'conversation_id', 'provider', 'model', 'status', 'error', 'input_tokens', 'output_tokens', 'duration_ms', 'created_at'])]
class AssistantUsage extends Model
{
    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_FAILED = 'FAILED';

    /** L'utilisateur a arrêté la réponse : le fournisseur n'a pas dit combien de tokens il avait comptés. */
    public const STATUS_STOPPED = 'STOPPED';

    public const UPDATED_AT = null;

    protected $table = 'ai_assistant_usages';

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
