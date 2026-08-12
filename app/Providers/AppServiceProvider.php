<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Pagination\Paginator;
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
         * Pagination is drawn in this application's own language.
         *
         * Laravel's default is `pagination::tailwind`, and it was never
         * overridden — so every `{{ $rows->links() }}` in a Bootstrap-5 UI
         * emitted a Tailwind paginator. It worked, because tailwind.config.js
         * scans the vendor pagination views, but it looked like nothing else in
         * the app and its `justify-between` left a hand's width of empty space
         * between the record count and the page buttons on a wide screen.
         *
         * Set here rather than per screen, so all seventeen paginated screens
         * agree without any of them carrying pagination markup of its own.
         */
        /*
         * Deliberately resources/views/pagination, not the conventional
         * resources/views/vendor/pagination: .gitignore carries a bare
         * `vendor/`, which git matches at any depth, so a view published to the
         * conventional path is ignored and would never reach the server.
         */
        Paginator::defaultView('pagination.gx');

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
