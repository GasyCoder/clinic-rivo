<?php

namespace Tests\Fixtures\Models;

/**
 * Same fixture table, but simulates a CDC §11 "critical data" model
 * (settled payment, validated invoice, ...) that must refuse force_delete.
 */
class ProtectedTestWidget extends TestWidget
{
    public function isForceDeleteProtected(): bool
    {
        return true;
    }
}
