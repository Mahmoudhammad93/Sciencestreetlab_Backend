<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    protected $fillable = ['payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
