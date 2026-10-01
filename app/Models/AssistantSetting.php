<?php

namespace App\Models;

use App\Enums\AssistantProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * ADR-222 — les réglages de l'assistant de cette base (un site, ou le portail).
 *
 * Une seule ligne. La clé du fournisseur est chiffrée par le cast `encrypted`
 * (APP_KEY) et masquée de toute sérialisation : `toArray()`, `toJson()` et les
 * props Inertia ne la contiennent jamais. Seul AssistantConfiguration la lit, au
 * moment d'appeler le fournisseur.
 *
 * Pas de trait `Auditable` : il écrirait les valeurs brutes — clé chiffrée
 * comprise — dans le journal. Chaque changement est audité par
 * UpdateAssistantSettingsAction, clé réduite à « remplacée » ou « retirée ».
 */
#[Fillable([
    'enabled', 'provider', 'model', 'api_key', 'api_key_updated_at',
    'max_output_tokens', 'temperature', 'timeout_seconds',
    'rate_limit_per_hour', 'daily_limit_per_user', 'monthly_token_budget', 'instructions',
    'updated_by', 'external_updated_by_uuid', 'external_updated_by_name',
])]
#[Hidden(['api_key'])]
class AssistantSetting extends Model
{
    protected $table = 'ai_assistant_settings';

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'provider' => AssistantProvider::class,
            'api_key' => 'encrypted',
            'api_key_updated_at' => 'datetime',
            'max_output_tokens' => 'integer',
            'temperature' => 'decimal:2',
            'timeout_seconds' => 'integer',
            'rate_limit_per_hour' => 'integer',
            'daily_limit_per_user' => 'integer',
            'monthly_token_budget' => 'integer',
        ];
    }

    /** La ligne de cette base ; une base non migrée n'en a pas, et ne casse rien. */
    public static function current(): ?self
    {
        try {
            return Schema::hasTable('ai_assistant_settings') ? static::query()->first() : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function updatedByName(): ?string
    {
        if ($this->external_updated_by_name) {
            return $this->external_updated_by_name;
        }

        return $this->updated_by ? User::query()->whereKey($this->updated_by)->value('name') : null;
    }
}
