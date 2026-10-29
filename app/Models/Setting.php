<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A stored override for one runtime setting. Defaults live in config('incidents.settings').
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
