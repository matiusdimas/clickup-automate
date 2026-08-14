<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClickUpTaskAssignee extends Model
{
    use HasFactory;

    protected $table = 'clickup_task_assignees';

    protected $fillable = [
        'clickup_task_cache_id',
        'clickup_task_id',
        'tiket_id',
        'clickup_user_id',
        'user_name',
        'user_email',
        'assigned_at',
    ];

    protected $casts = [
        'clickup_user_id' => 'integer',
        'assigned_at' => 'datetime',
    ];

    /**
     * Relationship to the parent task cache entry.
     */
    public function taskCache(): BelongsTo
    {
        return $this->belongsTo(ClickUpTaskCache::class, 'clickup_task_cache_id');
    }
}
