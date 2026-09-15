<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Actions\Pharmacy\ManageMedicineCategoryAction;
use App\Actions\Pharmacy\SetMedicineActiveAction;
use App\Actions\Pharmacy\UpdateMedicineProductAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\UpdateMedicineProductRequest;
use App\Models\Medicine;
use App\Models\MedicineCategory;
use App\Services\Catalog\CatalogActor;
use App\Services\Pharmacy\MedicineCatalogPresenter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-098 — the site's medicine records and medicine families, corrected from
 * the central portal. Every write goes through the Actions the clinic uses,
 * with the remote Super Admin as actor, so the rules and audit are the site's.
 */
class PharmacyCatalogController extends Controller
{
    public function __construct(private readonly MedicineCatalogPresenter $presenter) {}

    public function showMedicine(Request $request, string $medicineUuid): JsonResponse
    {
        $actor = $this->authorizeActor($request, 'medicines.view');

        return response()->json(['data' => [
            'medicine' => $this->presenter->medicine($this->medicine($medicineUuid)),
            ...$this->presenter->formOptions($actor->can('medicine_suppliers.view')),
        ]]);
    }

    public function updateMedicine(Request $request, string $medicineUuid, UpdateMedicineProductAction $action): JsonResponse
    {
        $medicine = $this->medicine($medicineUuid);
        $validated = $request->validate(UpdateMedicineProductRequest::rulesFor($medicine));
        $medicine = $action->execute($medicine, $validated, CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => "Médicament {$medicine->catalogItem->name} mis à jour.", 'data' => ['uuid' => $medicine->uuid]]);
    }

    public function deactivateMedicine(Request $request, string $medicineUuid, SetMedicineActiveAction $action): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $action->execute($this->medicine($medicineUuid), false, $validated['reason'], CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => 'Médicament désactivé : il n’est plus proposé à la vente, aux commandes ni aux entrées.']);
    }

    public function reactivateMedicine(Request $request, string $medicineUuid, SetMedicineActiveAction $action): JsonResponse
    {
        $action->execute($this->medicine($medicineUuid), true, null, CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => 'Médicament réactivé.']);
    }

    public function categories(Request $request): JsonResponse
    {
        $this->authorizeActor($request, 'medicine_categories.view');

        return response()->json(['data' => $this->presenter->categories()]);
    }

    public function storeCategory(Request $request, ManageMedicineCategoryAction $action): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $category = $action->create($validated, CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => "Famille {$category->name} créée.", 'data' => ['uuid' => $category->uuid]], 201);
    }

    public function updateCategory(Request $request, string $categoryUuid, ManageMedicineCategoryAction $action): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000']]);
        $category = $action->update($this->category($categoryUuid), $validated, CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => "Famille {$category->name} renommée."]);
    }

    public function archiveCategory(Request $request, string $categoryUuid, ManageMedicineCategoryAction $action): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $category = $action->archive($this->category($categoryUuid), $validated['reason'], CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => "Famille {$category->name} archivée."]);
    }

    public function restoreCategory(Request $request, string $categoryUuid, ManageMedicineCategoryAction $action): JsonResponse
    {
        $category = $action->restore($this->category($categoryUuid, archived: true), CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => "Famille {$category->name} restaurée."]);
    }

    private function medicine(string $uuid): Medicine
    {
        return Medicine::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function category(string $uuid, bool $archived = false): MedicineCategory
    {
        return MedicineCategory::query()
            ->when($archived, fn ($query) => $query->onlyTrashed())
            ->where('uuid', $uuid)
            ->firstOrFail();
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
