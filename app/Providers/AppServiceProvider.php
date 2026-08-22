<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Super admins bypass every policy/permission check. This is the single
        // audited seam through which tenant isolation is intentionally lifted.
        Gate::before(function (User $user, string $ability) {
            return $user->is_super_admin ? true : null;
        });
    }
}
