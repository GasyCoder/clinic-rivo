<?php

namespace App\Models\Concerns;

/**
 * Shared by SoftDeletable and Auditable — a trait, not a plain method on
 * each, so a model using both doesn't hit a "duplicate method" collision
 * (PHP allows the same trait pulled in twice via two parents; it does not
 * allow two unrelated traits defining the same method independently).
 */
trait HasAuditModule
{
    /**
     * CDC module name for the audit entry (e.g. "pharmacy", "reception").
     * Override per model — null is fine until a real module claims it.
     */
    protected function auditModule(): ?string
    {
        return null;
    }
}
