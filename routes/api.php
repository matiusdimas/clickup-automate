<?php

use App\Http\Controllers\Api\ClickUpModuleController;
use App\Http\Controllers\Api\ClickUpTaskController;
use App\Http\Controllers\Api\ClickUpSyncController;
use App\Http\Controllers\Api\ClickUpImportController;
use App\Http\Controllers\Api\ClickUpImportRuleController;
use App\Http\Controllers\Api\DashboardApiController;
use App\Http\Controllers\Api\TechnicianMappingController;
use Illuminate\Support\Facades\Route;

Route::prefix('clickup')->middleware(\App\Http\Middleware\CheckApiAuth::class)->group(function () {
    Route::get('/dashboard', [DashboardApiController::class, 'index']);
    
    // Modules & Overview
    Route::get('/overview', [ClickUpModuleController::class, 'overview']);
    Route::get('/modules', [ClickUpModuleController::class, 'index']);
    Route::post('/modules', [ClickUpModuleController::class, 'store']);
    Route::put('/modules/{module}', [ClickUpModuleController::class, 'update']);
    Route::patch('/modules/{module}', [ClickUpModuleController::class, 'update']);
    Route::delete('/modules/{module}', [ClickUpModuleController::class, 'destroy']);
    
    // Tasks
    Route::get('/tasks/all', [ClickUpTaskController::class, 'export']);
    Route::get('/tasks', [ClickUpTaskController::class, 'index']);
    Route::get('/tasks/{task}', [ClickUpTaskController::class, 'show']);
    
    // Sync
    Route::post('/sync', [ClickUpSyncController::class, 'sync']);
    Route::get('/sync/{syncToken}/progress', [ClickUpSyncController::class, 'progress']);
    
    // Import
    Route::post('/import', [ClickUpImportController::class, 'import']);
    Route::get('/import/{importToken}/progress', [ClickUpImportController::class, 'progress']);
    Route::post('/import/upload-preview', [ClickUpImportController::class, 'uploadPreview']);
    
    // Import Rules
    Route::get('/rules', [ClickUpImportRuleController::class, 'index']);
    Route::post('/rules', [ClickUpImportRuleController::class, 'store']);
    Route::delete('/rules/{rule}', [ClickUpImportRuleController::class, 'destroy']);
    
    // Technician Mappings
    Route::apiResource('technician-mappings', TechnicianMappingController::class);
});