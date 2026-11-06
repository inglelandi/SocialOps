<?php

namespace App\Providers;

use Anthropic\Client;
use App\Http\RateLimits;
use App\Models\User;
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
        Passport::authorizationView('auth.oauth-authorize'); // the Allow page Claude's connector sends the login to
        RateLimits::register(); // the JSON API, the MCP endpoint and the connector's sign-up
    }
}
