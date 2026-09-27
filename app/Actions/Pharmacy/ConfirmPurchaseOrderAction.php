<?php

namespace App\Actions\Pharmacy;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ADR-179 — la confirmation qu'un fournisseur envoie sur une commande.
 *
 * Certains fournisseurs confirment dispo et prix, d'autres livrent sans rien
 * annoncer. C'est donc une **trace**, jamais un passage obligé : une commande
 * sans confirmation se réceptionne exactement comme avant, et aucun statut
 * n'en dépend. Ce qu'elle apporte est de distinguer, à l'écran, « le
 * fournisseur a confirmé » de « nous ne savons pas ».
 *
 * Elle peut être enregistrée sur un brouillon — un fournisseur qui confirme
 * avant que la commande ferme parte est précisément l'un des deux cas décrits.
 */
class ConfirmPurchaseOrderAction
{
    /**
     * @param array{
     *   confirmed_at: string,
     *   reference?: ?string,
     *   notes?: ?string,
     *   attachment?: ?UploadedFile
     * } $data
     */
    public function execute(PurchaseOrder $order, array $data, CatalogActor $actor): PurchaseOrder
    {
        if ($actor->cannot('purchase_orders.confirm')) {
            throw new AuthorizationException('Vous ne pouvez pas enregistrer la confirmation du fournisseur.');
        }

        $order->loadMissing('supplier');
        $path = null;

        if (! empty($data['attachment'])) {
            $path = $data['attachment']->store(
                "suppliers/{$order->supplier->uuid}/order-confirmations/".now()->format('Y/m'),
                'local',
            );
        }

        try {
            return DB::transaction(function () use ($order, $data, $actor, $path): PurchaseOrder {
                $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
                $this->guardOpen($order);

                // Un nouveau document remplace l'ancien seulement à
                // l'enregistrement : une correction qui n'en porte pas garde
                // celui qui est déjà là.
                $previous = $path ? $order->supplier_confirmation_attachment_path : null;

                $order->update([
                    'supplier_confirmed_at' => $data['confirmed_at'],
                    'supplier_confirmation_reference' => filled($data['reference'] ?? null) ? trim((string) $data['reference']) : null,
                    'supplier_confirmation_notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
                    ...($path ? [
                        'supplier_confirmation_attachment_path' => $path,
                        'supplier_confirmation_attachment_original_name' => $data['attachment']->getClientOriginalName(),
                        'supplier_confirmation_attachment_mime_type' => $data['attachment']->getClientMimeType(),
                        'supplier_confirmation_attachment_size' => $data['attachment']->getSize(),
                    ] : []),
                    'supplier_confirmed_by' => $actor->localUserId(),
                    ...$actor->externalAttribution('supplier_confirmed'),
                    'updated_by' => $actor->localUserId(),
                    ...$actor->externalAttribution('updated'),
                ]);

                if ($previous) {
                    Storage::disk('local')->delete($previous);
                }

                return $order;
            });
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }

            throw $exception;
        }
    }

    /** Une trace saisie par erreur se retire ; l'audit garde ce qu'elle disait. */
    public function revert(PurchaseOrder $order, CatalogActor $actor): PurchaseOrder
    {
        if ($actor->cannot('purchase_orders.confirm')) {
            throw new AuthorizationException('Vous ne pouvez pas retirer la confirmation du fournisseur.');
        }

        return DB::transaction(function () use ($order, $actor): PurchaseOrder {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);

            if (! $order->isSupplierConfirmed()) {
                throw ValidationException::withMessages([
                    'supplier_confirmed_at' => 'Cette commande ne porte aucune confirmation du fournisseur.',
                ]);
            }

            $path = $order->supplier_confirmation_attachment_path;

            $order->update([
                'supplier_confirmed_at' => null,
                'supplier_confirmation_reference' => null,
                'supplier_confirmation_notes' => null,
                'supplier_confirmation_attachment_path' => null,
                'supplier_confirmation_attachment_original_name' => null,
                'supplier_confirmation_attachment_mime_type' => null,
                'supplier_confirmation_attachment_size' => null,
                'supplier_confirmed_by' => null,
                'external_supplier_confirmed_by_uuid' => null,
                'external_supplier_confirmed_by_name' => null,
                'updated_by' => $actor->localUserId(),
                ...$actor->externalAttribution('updated'),
            ]);

            if ($path) {
                Storage::disk('local')->delete($path);
            }

            return $order;
        });
    }

    private function guardOpen(PurchaseOrder $order): void
    {
        if ($order->status === PurchaseOrderStatus::Cancelled) {
            throw ValidationException::withMessages([
                'status' => 'Une commande annulée ne reçoit plus de confirmation.',
            ]);
        }
    }
}
