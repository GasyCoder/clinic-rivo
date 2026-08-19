<?php

namespace Tests\Fixtures\Models;

use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only fixture for exercising the SoftDeletable trait — not a real
 * business concept. Exists so the trait has a concrete model to
 * soft-delete/restore/force-delete against before any real CDC resource
 * (Patient, Invoice, ...) is built.
 */
class TestWidget extends Model
{
    use SoftDeletable;

    protected $table = 'widgets';

    protected $fillable = ['name'];
}
