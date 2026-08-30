<?php

namespace App\Enums;

enum TrashCategory: string
{
    case Patient = 'PATIENT';
    case CatalogItem = 'CATALOG_ITEM';
    case AddressEntry = 'ADDRESS_ENTRY';
    case MutualOrganization = 'MUTUAL_ORGANIZATION';
    case CashRegister = 'CASH_REGISTER';

    public function label(): string
    {
        return match ($this) {
            self::Patient => 'Patients',
            self::CatalogItem => 'Prestations et produits',
            self::AddressEntry => 'Adresses',
            self::MutualOrganization => 'Organismes mutuels',
            self::CashRegister => 'Caisses',
        };
    }

    public function singularLabel(): string
    {
        return match ($this) {
            self::Patient => 'Patient',
            self::CatalogItem => 'Prestation ou produit',
            self::AddressEntry => 'Adresse',
            self::MutualOrganization => 'Organisme mutuel',
            self::CashRegister => 'Caisse',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Patient => 'users',
            self::CatalogItem => 'list-index',
            self::AddressEntry => 'map-pin',
            self::MutualOrganization => 'shield-check',
            self::CashRegister => 'wallet',
        };
    }

    public function restorePermission(): string
    {
        return match ($this) {
            self::Patient => 'patients.restore',
            self::CatalogItem => 'catalog.items.restore',
            self::AddressEntry => 'address_entries.restore',
            self::MutualOrganization => 'mutual_organizations.restore',
            self::CashRegister => 'cash_registers.restore',
        };
    }

    /** @return array<int, array{code: string, label: string, singular_label: string, icon: string}> */
    public static function options(): array
    {
        return array_map(fn (self $category): array => [
            'code' => $category->value,
            'label' => $category->label(),
            'singular_label' => $category->singularLabel(),
            'icon' => $category->icon(),
        ], self::cases());
    }
}
