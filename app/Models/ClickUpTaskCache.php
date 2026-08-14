<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClickUpTaskCache extends Model
{
    protected $table = 'clickup_tasks_cache';

    protected $guarded = ['id'];

    /**
     * Get all assignees for this cached ClickUp task.
     */
    public function assignees(): HasMany
    {
        return $this->hasMany(ClickUpTaskAssignee::class, 'clickup_task_cache_id');
    }
}