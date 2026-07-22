<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['slug', 'name', 'description', 'grants', 'constraints', 'power', 'source', 'evolution_run_id'])]
class Item extends Model
{
    protected function casts(): array
    {
        return [
            'grants' => 'array',
            'constraints' => 'array',
        ];
    }
}
