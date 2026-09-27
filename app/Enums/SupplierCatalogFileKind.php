<?php

namespace App\Enums;

/**
 * ADR-097 — Excel catalogs get structured row extraction; PDF catalogs are
 * stored and previewed as opaque files only, never parsed (no PDF library
 * exists in this app, and a supplier PDF has no downstream editable use).
 */
enum SupplierCatalogFileKind: string
{
    case Excel = 'EXCEL';
    case Pdf = 'PDF';

    public function label(): string
    {
        return match ($this) {
            self::Excel => 'Excel',
            self::Pdf => 'PDF',
        };
    }
}
