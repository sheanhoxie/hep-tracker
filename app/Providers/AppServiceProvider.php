<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
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
        Model::preventLazyLoading(! $this->app->isProduction());

        // users.role: patient | practitioner | admin — content management is
        // manager-only (CONTENT-ADMIN.md W1); patients read, never write content.
        Gate::define('manage-content', fn (User $user): bool => in_array($user->role, ['practitioner', 'admin'], true));
    }
}
