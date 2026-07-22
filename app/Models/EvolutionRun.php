<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['kind', 'status', 'budget', 'changes', 'chronicle', 'error', 'started_at', 'finished_at'])]
class EvolutionRun extends Model
{
    protected function casts(): array
    {
        return [
            'budget' => 'array',
            'changes' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
