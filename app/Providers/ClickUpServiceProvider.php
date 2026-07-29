<?php

namespace App\Providers;

use App\Services\ClickUp\ClickUpApiClient;
use App\Services\ClickUp\ClickUpImportService;
use App\Services\ClickUp\ClickUpSyncService;
use App\Services\ClickUp\ExcelParserService;
use App\Services\ClickUp\ImportNormalizerService;
use Illuminate\Support\ServiceProvider;

class ClickUpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ClickUpApiClient::class, function ($app) {
            return new ClickUpApiClient();
        });

        $this->app->bind(ImportNormalizerService::class, function ($app) {
            return new ImportNormalizerService();
        });

        $this->app->bind(ExcelParserService::class, function ($app) {
            return new ExcelParserService();
        });

        $this->app->bind(ClickUpSyncService::class, function ($app) {
            return new ClickUpSyncService(
                $app->make(ClickUpApiClient::class),
                $app->make(ImportNormalizerService::class)
            );
        });

        $this->app->bind(ClickUpImportService::class, function ($app) {
            return new ClickUpImportService(
                $app->make(ClickUpApiClient::class),
                $app->make(ImportNormalizerService::class),
                $app->make(ClickUpSyncService::class)
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
