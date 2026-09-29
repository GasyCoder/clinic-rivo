<?php

namespace App\Actions\Settings;

use App\Enums\AssistantProvider;
use App\Models\AssistantSetting;
use App\Services\Assistant\AssistantConfiguration;
use App\Services\Audit\Auditor;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * ADR-222 — enregistrer les réglages de l'assistant de cette base.
 *
 * La clé :
 *   - champ vide ou absent  la clé enregistrée est gardée, jamais remplacée par du vide ;
 *   - champ rempli          la nouvelle clé remplace l'ancienne, chiffrée ;
 *   - retirer               un geste à part (removeKey), confirmé à l'écran.
 *
 * Changer de fournisseur sans donner de clé est refusé quand une clé est enregistrée :
 * elle appartient à l'ancien fournisseur et ne marcherait pas avec le nouveau.
 *
 * Chaque changement est audité ; la clé n'y figure jamais — seulement « remplacée »
 * ou « retirée ».
 */
class UpdateAssistantSettingsAction
{
    public const PERMISSION = 'ai_settings.update';

    /** Les champs gardés tels quels (la clé suit son propre chemin). */
    public const FIELDS = [
        'enabled', 'provider', 'model', 'max_output_tokens', 'temperature', 'timeout_seconds',
        'rate_limit_per_hour', 'daily_limit_per_user', 'monthly_token_budget', 'instructions',
    ];

    public function __construct(
        private readonly Auditor $auditor,
        private readonly AssistantConfiguration $configuration,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, CatalogActor $actor): AssistantSetting
    {
        $this->authorize($actor);
        $this->ensureInstalled();

        $setting = DB::transaction(function () use ($data, $actor): AssistantSetting {
            $setting = AssistantSetting::query()->lockForUpdate()->first() ?? new AssistantSetting;
            $before = $this->snapshot($setting);
            $newKey = trim((string) ($data['api_key'] ?? ''));
            $provider = filled($data['provider'] ?? null) ? AssistantProvider::from((string) $data['provider']) : null;

            if ($newKey === '' && filled($setting->api_key) && $setting->provider !== null && $provider !== $setting->provider) {
                throw ValidationException::withMessages([
                    'api_key' => 'La clé enregistrée appartient à '.$setting->provider->label().'. Saisissez la clé de '
                        .($provider?->label() ?? 'ce fournisseur').', ou retirez d’abord la clé enregistrée.',
                ]);
            }

            if ($newKey !== '' && $provider === null) {
                throw ValidationException::withMessages(['provider' => 'Choisissez le fournisseur de cette clé.']);
            }

            $setting->fill([
                'enabled' => (bool) ($data['enabled'] ?? false),
                'provider' => $provider,
                'model' => filled($data['model'] ?? null) ? trim((string) $data['model']) : null,
                'max_output_tokens' => $data['max_output_tokens'] ?? null,
                'temperature' => ($data['temperature'] ?? null) === null || $data['temperature'] === '' ? null : round((float) $data['temperature'], 2),
                'timeout_seconds' => $data['timeout_seconds'] ?? null,
                'rate_limit_per_hour' => $data['rate_limit_per_hour'] ?? null,
                'daily_limit_per_user' => $data['daily_limit_per_user'] ?? null,
                'monthly_token_budget' => $data['monthly_token_budget'] ?? null,
                'instructions' => filled($data['instructions'] ?? null) ? trim((string) $data['instructions']) : null,
                'updated_by' => $actor->localUserId(),
            ] + $actor->externalAttribution('updated'));

            if ($newKey !== '') {
                $setting->api_key = $newKey;
                $setting->api_key_updated_at = now();
            }

            $setting->save();

            $after = $this->snapshot($setting);
            $changes = array_filter($after, fn ($value, $field) => ($before[$field] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);

            if ($newKey !== '') {
                $changes['api_key'] = 'remplacée';
            }

            if ($changes !== []) {
                $this->auditor->record(
                    'ai_settings.update',
                    entity: $setting,
                    newValues: $changes,
                    oldValues: array_intersect_key($before, $changes),
                    module: 'settings',
                );
            }

            return $setting;
        });

        $this->configuration->forget();

        return $setting;
    }

    public function removeKey(CatalogActor $actor): void
    {
        $this->authorize($actor);
        $this->ensureInstalled();

        DB::transaction(function () use ($actor): void {
            $setting = AssistantSetting::query()->lockForUpdate()->first();

            if ($setting === null || blank($setting->api_key)) {
                throw ValidationException::withMessages(['api_key' => 'Aucune clé n’est enregistrée ici.']);
            }

            $setting->forceFill([
                'api_key' => null,
                'api_key_updated_at' => now(),
                'updated_by' => $actor->localUserId(),
            ] + $actor->externalAttribution('updated'))->save();

            $this->auditor->record(
                'ai_settings.key_remove',
                entity: $setting,
                newValues: ['api_key' => 'retirée'],
                module: 'settings',
            );
        });

        $this->configuration->forget();
    }

    private function authorize(CatalogActor $actor): void
    {
        if ($actor->cannot(self::PERMISSION)) {
            throw new AuthorizationException('Vous ne pouvez pas régler l’assistant IA.');
        }
    }

    /** Une base dont les migrations n'ont pas été jouées le dit, au lieu d'une erreur SQL. */
    private function ensureInstalled(): void
    {
        if (! Schema::hasTable('ai_assistant_settings')) {
            throw ValidationException::withMessages([
                'site_code' => 'Les réglages de l’assistant ne sont pas encore installés sur cette base : jouez les migrations (php artisan migrate).',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(AssistantSetting $setting): array
    {
        return [
            'enabled' => (bool) $setting->enabled,
            'provider' => $setting->provider?->value,
            'model' => $setting->model,
            'max_output_tokens' => $setting->max_output_tokens,
            'temperature' => $setting->temperature === null ? null : (float) $setting->temperature,
            'timeout_seconds' => $setting->timeout_seconds,
            'rate_limit_per_hour' => $setting->rate_limit_per_hour,
            'daily_limit_per_user' => $setting->daily_limit_per_user,
            'monthly_token_budget' => $setting->monthly_token_budget,
            'instructions' => $setting->instructions,
        ];
    }
}
