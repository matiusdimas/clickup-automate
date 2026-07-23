<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClickUpModule extends Model
{
    protected $table = 'clickup_modules';

    protected $fillable = [
        'module_name',
        'clickup_view_id',
        'clickup_list_id',
        'is_active',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }
}