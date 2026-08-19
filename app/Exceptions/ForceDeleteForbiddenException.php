<?php

namespace App\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * CDC §11: force_delete must be refused on critical data (settled payment,
 * validated invoice/prescription/lab result/surgical act/stock movement,
 * cash closing, completed transfer, audit log). Thrown, not silently
 * swallowed, so a caller can never mistake a blocked deletion for a
 * successful one.
 */
class ForceDeleteForbiddenException extends RuntimeException
{
    public function __construct(Model $model)
    {
        parent::__construct(sprintf(
            'force_delete refusé sur %s#%s : donnée critique protégée (CDC §11). Utiliser cancel, correct, archive ou reverse.',
            $model::class,
            $model->getKey(),
        ));
    }
}
