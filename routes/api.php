<?php

use App\Http\Controllers\Api\ClickUpController;
use App\Http\Controllers\Api\DashboardApiController;
use App\Http\Controllers\Api\TechnicianMappingController;
use Illuminate\Support\Facades\Route;

Route::prefix('clickup')->middleware(\App\Http\Middleware\CheckApiAuth::class)->group(function () {
    Route::get('/dashboard', [DashboardApiController::class, 'index']);
    Route::get('/overview', [ClickUpController::class, 'overview']);
    Route::get('/modules', [ClickUpController::class, 'modules']);
    Route::post('/modules', [ClickUpController::class, 'storeModule']);
    Route::put('/modules/{module}', [ClickUpController::class, 'updateModule']);
    Route::patch('/modules/{module}', [ClickUpController::class, 'updateModule']);
    Route::delete('/modules/{module}', [ClickUpController::class, 'destroyModule']);
    Route::get('/tasks/all', [ClickUpController::class, 'exportTasks']);
    Route::get('/tasks', [ClickUpController::class, 'tasks']);
    Route::get('/tasks/{task}', [ClickUpController::class, 'showTask']);
    Route::post('/sync', [ClickUpController::class, 'sync']);
    Route::get('/sync/{syncToken}/progress', [ClickUpController::class, 'syncProgress']);
    Route::post('/import', [ClickUpController::class, 'import']);
    Route::get('/import/{importToken}/progress', [ClickUpController::class, 'importProgress']);
    Route::post('/import/upload-preview', [ClickUpController::class, 'uploadPreview']);
    Route::get('/rules', [ClickUpController::class, 'rules']);
    Route::post('/rules', [ClickUpController::class, 'storeRule']);
    Route::delete('/rules/{rule}', [ClickUpController::class, 'destroyRule']);
    Route::apiResource('technician-mappings', TechnicianMappingController::class);
});