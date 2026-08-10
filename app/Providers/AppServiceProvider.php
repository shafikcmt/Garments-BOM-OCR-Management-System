<?php

namespace App\Providers;

use App\Models\User;
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
        /*
         * The admin role passes every permission check.
         *
         * Until now admin could only do what it had been granted one permission
         * at a time — all 41 of them, listed out. That works only for as long as
         * nobody adds a 42nd: the moment a new `can:` check appears anywhere,
         * admin fails it until someone remembers to grant it, and the account
         * that is supposed to fix the problem is the one locked out of it.
         *
         * Returning true short-circuits the check; returning NULL (not false)
         * lets everyone else fall through to the normal role and permission
         * lookup, so this grants admin more and takes nothing from anyone.
         *
         * Deliberately keyed on the role rather than a column on users, so
         * there is exactly one place that decides who is a super user and it is
         * the same place the rest of the app already looks.
         */
        Gate::before(function (User $user) {
            return $user->hasRole('admin') ? true : null;
        });
    }
}
