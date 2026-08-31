<?php

use App\Console\Commands\RewardsExpire;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->alias([
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    /*
     * The showroom's first scheduled work.
     *
     * One entry, and it is a tidying job rather than a load-bearing one:
     * nothing in the application trusts these statuses over the dates behind
     * them, so a host whose cron is not running is a host with a slightly
     * stale rewards list, not a broken one. That is deliberate - a showroom
     * floor is not a place to discover that a scheduler stopped a week ago.
     *
     * `withoutOverlapping` because it does not need to run twice at once, and
     * `onOneServer` because the day this is deployed behind two web nodes,
     * both of their crons will fire.
     *
     * For this to run at all, the host needs the one cron entry Laravel asks
     * for: `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1`.
     */
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command(RewardsExpire::class)
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->onOneServer();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
