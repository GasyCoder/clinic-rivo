<?php

namespace App\Actions\Bonus;

use App\Models\BonusCategory;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * ADR-212 — archiver une catégorie (ADR-009) : elle ne compte plus, mais ses
 * bonus déjà validés restent lisibles et se versent. Elle se restaure.
 */
class ArchiveBonusCategoryAction
{
    public function archive(BonusCategory $category, string $reason, User $actor): void
    {
        if ($actor->cannot('bonus_categories.archive')) {
            throw new AuthorizationException('Vous ne pouvez pas archiver une catégorie de bonus.');
        }

        $category->delete_reason = $reason;
        $category->delete();
    }

    public function restore(BonusCategory $category, User $actor): void
    {
        if ($actor->cannot('bonus_categories.restore')) {
            throw new AuthorizationException('Vous ne pouvez pas restaurer une catégorie de bonus.');
        }

        $category->restore();
    }
}
