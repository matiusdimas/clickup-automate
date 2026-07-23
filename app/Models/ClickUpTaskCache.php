<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClickUpTaskCache extends Model
{
    protected $table = 'clickup_tasks_cache';

    protected $guarded = ['id'];
}