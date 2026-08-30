<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'key', 'request_hash', 'method', 'path', 'response_status',
    'response_body', 'completed_at',
])]
class ApiIdempotencyRecord extends Model
{
    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'completed_at' => 'datetime',
        ];
    }
}
