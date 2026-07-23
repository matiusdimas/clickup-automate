<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClickUpImportRule extends Model
{
    protected $table = 'clickup_import_rules';

    protected $fillable = [
        'excel_field',
        'excel_value',
        'target_module',
        'source_format',
    ];
}
