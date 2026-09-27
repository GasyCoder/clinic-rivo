<?php

namespace App\Actions\Pharmacy;

use App\Enums\SupplierCatalogFileKind;
use App\Models\MedicineSupplier;
use App\Models\SupplierCatalog;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ADR-097 — spec §2: stores one file in a supplier's "Drive style" folder.
 * A newly uploaded catalog is never active by default (activation is an
 * explicit, separate step) and never auto-imported (Excel-only, separate).
 *
 * ADR-098 — the uploader is a local account or the remote Super Admin of the
 * central portal; a remote upload keeps the portal identity on the catalog.
 */
class UploadSupplierCatalogAction
{
    private const MIME_KINDS = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => SupplierCatalogFileKind::Excel,
        'application/vnd.ms-excel' => SupplierCatalogFileKind::Excel,
        'application/pdf' => SupplierCatalogFileKind::Pdf,
    ];

    /** @param array{file: UploadedFile, catalog_date?: ?string, notes?: ?string} $data */
    public function execute(MedicineSupplier $supplier, array $data, CatalogActor $actor): SupplierCatalog
    {
        if ($actor->cannot('supplier_catalogs.create')) {
            throw new AuthorizationException('Vous ne pouvez pas importer de catalogue fournisseur.');
        }

        /** @var UploadedFile $file */
        $file = $data['file'];
        $kind = self::MIME_KINDS[$file->getMimeType()] ?? null;

        if ($kind === null) {
            throw ValidationException::withMessages([
                'file' => 'Seuls les fichiers Excel (.xlsx, .xls) ou PDF sont acceptés.',
            ]);
        }

        $path = $file->store("suppliers/{$supplier->uuid}/catalogs/".now()->format('Y/m'), 'local');

        if ($path === false) {
            throw ValidationException::withMessages([
                'file' => 'Le catalogue n’a pas pu être enregistré. Veuillez réessayer.',
            ]);
        }

        try {
            return $supplier->catalogs()->create([
                'original_name' => $this->safeOriginalName($file),
                'path' => $path,
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize(),
                'kind' => $kind,
                'catalog_date' => $data['catalog_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->localUserId(),
                'updated_by' => $actor->localUserId(),
                ...$actor->externalAttribution('created'),
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }
    }

    private function safeOriginalName(UploadedFile $file): string
    {
        $name = basename($file->getClientOriginalName());
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\]/u', '_', $name) ?: 'catalogue';

        return Str::limit($name, 255, '');
    }
}
