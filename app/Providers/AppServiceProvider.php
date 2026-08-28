<?php

namespace App\Providers;

use App\Services\Competition\CredentialGateway;
use App\Services\Competition\LogCredentialGateway;
use App\Services\Competition\MailCredentialGateway;
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
         * Which gateway is bound follows the mailer, so putting real mail
         * credentials in .env is the whole switch — there is no code to edit
         * on competition day and no second flag that could disagree with the
         * first.
         *
         *   MAIL_MAILER=log    → LogCredentialGateway   (records the dispatch)
         *   MAIL_MAILER=array  → LogCredentialGateway   (tests)
         *   anything else      → MailCredentialGateway  (really sends)
         *
         * Defaulting the OTHER way round would be the dangerous mistake: a
         * missing or misspelt MAIL_MAILER would then send nothing while
         * reporting success, and nobody would know until the day.
         */
        $this->app->bind(CredentialGateway::class, function (): CredentialGateway {
            return in_array(config('mail.default'), ['log', 'array', null], true)
                ? new LogCredentialGateway
                : new MailCredentialGateway;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
