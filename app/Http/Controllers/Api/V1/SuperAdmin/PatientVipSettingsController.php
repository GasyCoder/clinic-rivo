<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Actions\Patient\UpdatePatientVipSettingsAction;
use App\Enums\DiscountType;
use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientVipSetting;
use App\Services\Catalog\CatalogActor;
use App\Services\Patient\PatientVipClassifier;
use App\Support\Billing\DiscountRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Les seuils des patients VIP d'un site (ADR-133). Chaque site a les siens : ses
 * patients et ses encaissements ne se mélangent pas à ceux d'un autre (ADR-001).
 * Le portail n'écrit jamais ici directement : chaque appel arrive par l'API,
 * avec l'identité du Super Administrateur, réautorisé localement (ADR-004/025/027).
 */
class PatientVipSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->authorizeActor($request, 'patient_vip.view');

        return response()->json(['data' => $this->payload(PatientVipSetting::current())]);
    }

    /**
     * Combien de patients seraient VIP avec ces seuils, **sans rien enregistrer**.
     * Régler un seuil à l'aveugle n'a pas de sens : on veut voir ce qu'il fait.
     */
    public function preview(Request $request): JsonResponse
    {
        $this->authorizeActor($request, 'patient_vip.view');
        $validated = $this->validated($request);

        return response()->json(['data' => $this->payload(new PatientVipSetting([...$validated]), persisted: false)]);
    }

    public function update(Request $request, UpdatePatientVipSettingsAction $action): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'patient_vip.update');
        $setting = $action->execute($this->validated($request), $actor);

        return response()->json([
            'message' => 'Seuils des patients VIP enregistrés pour ce site.',
            'data' => $this->payload($setting),
        ]);
    }

    /** @return array{enabled: bool, min_episodes: int, min_amount: string, window_months: int, discount_type: ?string, discount_value: ?string} */
    private function validated(Request $request): array
    {
        $validated = $request->validate(self::rules(), self::messages());
        $discount = DiscountType::rule($validated['discount_type'] ?? null, $validated['discount_value'] ?? null);

        return [
            'enabled' => (bool) $validated['enabled'],
            'min_episodes' => (int) $validated['min_episodes'],
            'min_amount' => number_format((float) $validated['min_amount'], 2, '.', ''),
            'window_months' => (int) $validated['window_months'],
            'discount_type' => $discount['type']->value ?? null,
            'discount_value' => $discount !== null ? number_format((float) $discount['value'], 2, '.', '') : null,
        ];
    }

    /**
     * Les règles des seuils et de la remise VIP, partagées avec le portail : deux
     * copies finiraient par accepter d'un côté ce que l'autre refuse.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'min_episodes' => ['required', 'integer', 'min:1', 'max:1000'],
            // Ariary : pas de subdivision utile, mais la colonne est décimale.
            'min_amount' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'window_months' => ['required', 'integer', 'min:1', 'max:120'],
            // ADR-192 — la remise VIP, facultative.
            ...DiscountRules::pair('discount_type', 'discount_value', required: false),
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'min_episodes.min' => 'Il faut au moins un passage.',
            'window_months.min' => 'La période est d’au moins un mois.',
            'window_months.max' => 'La période ne peut pas dépasser 120 mois.',
            ...DiscountRules::messages('discount_type', 'discount_value'),
        ];
    }

    /** @return array<string, mixed>|null */
    private function payload(?PatientVipSetting $setting, bool $persisted = true): ?array
    {
        $classifier = new PatientVipClassifier($setting);

        return [
            'site' => ['code' => config('rivo.site.code'), 'name' => config('rivo.site.name')],
            'configured' => $setting !== null && $persisted,
            'enabled' => $setting?->enabled ?? false,
            'min_episodes' => $setting?->min_episodes,
            'min_amount' => $setting !== null ? (string) $setting->min_amount : null,
            'window_months' => $setting?->window_months,
            // ADR-192 — la remise VIP réglée avec ces seuils.
            'discount_type' => $setting?->discount_type?->value,
            'discount_value' => $setting?->discount_value !== null ? (string) $setting->discount_value : null,
            'discount' => $setting?->discount_type?->describe((string) $setting->discount_value),
            'rule' => $classifier->rule(),
            // Ce que ces seuils donnent aujourd'hui sur ce site.
            'vip_count' => count($classifier->vipIds()),
            'patients_count' => Patient::query()->count(),
            'updated_at' => $persisted ? $setting?->updated_at?->toIso8601String() : null,
            'updated_by' => $persisted ? ($setting?->external_updated_by_name ?? null) : null,
        ];
    }

    private function authorizeActor(Request $request, string $permission): CatalogActor
    {
        $actor = CatalogActor::fromRemoteRequest($request);

        if ($actor->cannot($permission)) {
            throw new AuthorizationException('Cette action distante n’est pas autorisée.');
        }

        return $actor;
    }
}
