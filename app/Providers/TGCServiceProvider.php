<?php

namespace App\Providers;

use App\Services\TGC\TGCService;
use App\Services\TGC\TGCSessionManager;
use Illuminate\Support\ServiceProvider;

class TGCServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TGCSessionManager::class, fn () => new TGCSessionManager());
        $this->app->singleton(TGCService::class, fn ($app) => new TGCService($app->make(TGCSessionManager::class)));
    }
}
