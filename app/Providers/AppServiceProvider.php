<?php

namespace App\Providers;

use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        #
    }

    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureRateLimiting();
    }

    # Super admin is named, never derived: it holds every ability without holding
    # a single permission row, so a subset test would report it grants nothing.
    protected function configureAuthorization(): void
    {
        Gate::before(
            fn (User $user) => $user->hasRole(Role::SUPER_ADMIN) ? true : null,
        );
    }

    # Keyed by token *and* IP: nobody is signed in to key it by, and one person
    # hammering their own reward must not spend everybody else's allowance.
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('shuffle', function (Request $request) {
            return Limit::perMinute(10)->by(
                $request->route('token').'|'.$request->ip(),
            );
        });
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
