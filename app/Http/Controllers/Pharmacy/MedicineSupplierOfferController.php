<?php

namespace App\Http\Controllers\Pharmacy;

use App\Actions\Pharmacy\SetMedicineSupplierOfferAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\StoreMedicineSupplierOfferRequest;
use App\Models\Medicine;
use App\Models\MedicineSupplier;
use App\Services\Catalog\CatalogActor;
use Illuminate\Http\RedirectResponse;

class MedicineSupplierOfferController extends Controller
{
    public function store(StoreMedicineSupplierOfferRequest $request, MedicineSupplier $supplier, SetMedicineSupplierOfferAction $action): RedirectResponse
    {
        $medicine = Medicine::query()->where('uuid', $request->validated('medicine_uuid'))->firstOrFail();

        $action->execute(
            medicine: $medicine,
            supplier: $supplier,
            quotedPrice: $request->validated('quoted_price'),
            reason: $request->validated('change_reason'),
            actor: CatalogActor::fromUser($request->user()),
            supplierReference: $request->validated('supplier_reference'),
        );

        return back()->with('status', 'Prix fournisseur enregistré.');
    }
}
