<?php

namespace App\Providers;

use App\Services\Competition\CredentialGateway;
use App\Services\Competition\LogCredentialGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * The Email Gateway seam.
         *
         * LogCredentialGateway is the development implementation. When gateway
         * credentials and configuration are supplied, the vendor client is
         * written against CredentialGateway and this single line changes —
         * nothing in provisioning or delivery needs touching.
         */
        $this->app->bind(CredentialGateway::class, LogCredentialGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
