<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('clickup:sync {token}', function (string $token) {
    /** @var \App\Services\ClickUp\ClickUpSyncService $syncService */
    $syncService = app(\App\Services\ClickUp\ClickUpSyncService::class);
    $syncService->runSync($token);
})->purpose('Run ClickUp sync for a given token asynchronously');
