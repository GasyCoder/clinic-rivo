<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Enums\PaymentMethodCategory;
use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Services\Cash\PaymentMethodManager;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tenders accepted by one site's cash desk. Each site keeps its own list —
 * mobile money operators differ from one town to the next — so the portal
 * never writes here directly: every call arrives through this API with the
 * central actor's identity, re-authorized locally (ADR-004/025/027).
 */
class PaymentMethodController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeActor($request, 'payment_methods.view');
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE', 'ALL'])],
        ]);
        $status = $validated['status'] ?? 'ALL';

        $methods = PaymentMethod::query()
            ->withCount('payments')
            ->when($status === 'ACTIVE', fn ($query) => $query->where('active', true))
            ->when($status === 'INACTIVE', fn ($query) => $query->where('active', false))
            ->when(filled($validated['search'] ?? null), fn ($query) => $query->where(
                fn ($inner) => $inner
                    ->where('name', 'like', '%'.$validated['search'].'%')
                    ->orWhere('code', 'like', '%'.strtoupper($validated['search']).'%'),
            ))
            ->orderByDesc('active')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $methods->map(fn (PaymentMethod $method) => $this->serialize($method))->values(),
            'meta' => [
                'site' => ['code' => config('rivo.site.code'), 'name' => config('rivo.site.name')],
                'summary' => [
                    'displayed' => $methods->count(),
                    'active' => PaymentMethod::query()->where('active', true)->count(),
                    'inactive' => PaymentMethod::query()->where('active', false)->count(),
                    'cash_affecting' => PaymentMethod::query()
                        ->where('active', true)
                        ->where('affects_cash_balance', true)
                        ->count(),
                ],
                'categories' => collect(PaymentMethodCategory::cases())
                    ->sortBy(fn (PaymentMethodCategory $category) => $category->position())
                    ->map(fn (PaymentMethodCategory $category) => [
                        'value' => $category->value,
                        'label' => $category->label(),
                        'icon' => $category->icon(),
                    ])->values(),
            ],
        ]);
    }

    public function store(Request $request, PaymentMethodManager $manager): JsonResponse
    {
        $this->authorizeActor($request, 'payment_methods.create');
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:100'],
            'category' => ['required', Rule::enum(PaymentMethodCategory::class)],
            'affects_cash_balance' => ['required', 'boolean'],
            'requires_reference' => ['required', 'boolean'],
        ]);
        $method = $manager->create(
            $validated['code'],
            $validated['name'],
            PaymentMethodCategory::from($validated['category']),
            (bool) $validated['affects_cash_balance'],
            (bool) $validated['requires_reference'],
        );

        return response()->json([
            'message' => 'Mode de paiement ajouté au référentiel du site.',
            'data' => $this->serialize($method),
        ], 201);
    }

    public function update(Request $request, string $paymentMethodUuid, PaymentMethodManager $manager): JsonResponse
    {
        $this->authorizeActor($request, 'payment_methods.update');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'category' => ['required', Rule::enum(PaymentMethodCategory::class)],
            'affects_cash_balance' => ['required', 'boolean'],
            'requires_reference' => ['required', 'boolean'],
        ]);
        $method = PaymentMethod::query()->where('uuid', $paymentMethodUuid)->firstOrFail();
        $method = $manager->update(
            $method,
            $validated['name'],
            PaymentMethodCategory::from($validated['category']),
            (bool) $validated['affects_cash_balance'],
            (bool) $validated['requires_reference'],
        );

        return response()->json([
            'message' => 'Mode de paiement mis à jour.',
            'data' => $this->serialize($method),
        ]);
    }

    public function activate(Request $request, string $paymentMethodUuid, PaymentMethodManager $manager): JsonResponse
    {
        $this->authorizeActor($request, 'payment_methods.activate');
        $method = PaymentMethod::query()->where('uuid', $paymentMethodUuid)->firstOrFail();
        $method = $manager->activate($method);

        return response()->json([
            'message' => 'Mode de paiement activé.',
            'data' => $this->serialize($method),
        ]);
    }

    public function deactivate(Request $request, string $paymentMethodUuid, PaymentMethodManager $manager): JsonResponse
    {
        $this->authorizeActor($request, 'payment_methods.deactivate');
        $method = PaymentMethod::query()->where('uuid', $paymentMethodUuid)->firstOrFail();
        $method = $manager->deactivate($method);

        return response()->json([
            'message' => 'Mode de paiement désactivé.',
            'data' => $this->serialize($method),
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(PaymentMethod $method): array
    {
        return [
            'uuid' => $method->uuid,
            'code' => $method->code,
            'name' => $method->name,
            'category' => $method->category->value,
            'category_label' => $method->category->label(),
            'category_icon' => $method->category->icon(),
            'active' => (bool) $method->active,
            'affects_cash_balance' => (bool) $method->affects_cash_balance,
            'requires_reference' => (bool) $method->requires_reference,
            'payments_count' => (int) ($method->payments_count ?? 0),
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
