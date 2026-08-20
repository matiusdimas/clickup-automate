<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClickUpAssigneeRule extends Model
{
    use HasFactory;

    protected $table = 'clickup_assignee_rules';

    protected $fillable = [
        'rule_name',
        'app_category',
        'target_app',
        'assignee_ids',
        'assignee_names',
        'conditions',
        'is_active',
        'priority',
    ];

    protected $casts = [
        'assignee_ids' => 'array',
        'assignee_names' => 'array',
        'conditions' => 'array',
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];
}
