<?php

namespace App\Providers;

use App\Models\OrderHasPaid;
use App\Observers\OrderHasPaidObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        OrderHasPaid::observe(OrderHasPaidObserver::class);

        $this->bindResendApiKey();
    }

    /**
     * Bind the Resend API key from the RESEND_KEY environment variable
     * into the mailer config.
     */
    private function bindResendApiKey(): void
    {
        $key = env('RESEND_KEY');

        if ($key) {
            $this->app['config']->set('services.resend.key', $key);
        }
    }
}
