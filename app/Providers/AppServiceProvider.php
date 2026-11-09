<?php

namespace App\Providers;

use Anthropic\Client;
use App\Http\RateLimits;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Client::class, fn () => new Client(apiKey: config('services.anthropic.key')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('admin', fn (User $user) => $user->is_admin); // the Settings page and the Admin tick on the Staff page
        // a login limited to some clients would never see a client it adds
        Gate::define('add-clients', fn (User $user) => $user->client_ids === null ? Response::allow() : Response::deny(__('Only a login that sees every client can add clients.')));
        Passport::authorizationView('auth.oauth-authorize'); // the Allow page Claude's connector sends the login to
        RateLimits::register(); // the JSON API, the MCP endpoint and the connector's sign-up
    }
}
