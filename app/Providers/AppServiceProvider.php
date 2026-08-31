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
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureRateLimiting();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    /**
     * The super admin holds every ability without holding every permission
     * row, so a capability added to the enum is theirs the moment it exists.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(
            fn (User $user) => $user->hasRole(Role::SUPER_ADMIN) ? true : null,
        );
    }

    /**
     * The limiter behind the one public page in this application.
     *
     * The auth limiters live in `FortifyServiceProvider` with the rest of the
     * sign-in machinery; this one is not about signing in, so it is here.
     *
     * Keyed by IP because there is nobody signed in to key it by, and by the
     * token as well so that one person hammering their own reward cannot spend
     * the allowance of everybody else behind the same showroom router. Ten a
     * minute is far more than scanning a QR code and pressing a button needs,
     * and far less than enumerating a 64-character token would.
     */
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
