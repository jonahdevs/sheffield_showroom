<?php

use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\PurchaseController;
use App\Http\Controllers\Admin\RewardCampaignController;
use App\Http\Controllers\Admin\RewardController;
use App\Http\Controllers\Admin\RewardOverviewController;
use App\Http\Controllers\Admin\RewardRedemptionController;
use App\Http\Controllers\Admin\RewardWinnerController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\ShuffleSessionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VisitController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ShuffleController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::controller(DashboardController::class)
        ->middleware('permission:dashboard.view')
        ->group(function () {
            Route::get('dashboard', 'index')->name('dashboard');

            Route::get('dashboard/export', 'export')->name('dashboard.export');
        });
});

# =========================================================================
# The customer shuffle
# =========================================================================
#
# The only pages with no sign-in behind them, because a customer has no
# account. The token in the URL is the whole of the authorisation: 64 random
# characters, good for one reward and one day. The throttle is keyed by IP
# because there is nobody signed in to key it by.

Route::middleware('throttle:shuffle')
    ->prefix('rewards/shuffle')
    ->name('rewards.shuffle.')
    ->controller(ShuffleController::class)
    ->group(function () {
        Route::get('{token}', 'show')->name('show');
        Route::post('{token}', 'store')->name('store');
    });

# =========================================================================
# Administration
# =========================================================================
#
# Every route names the capability it needs, so the gate is readable here
# rather than only inside the controller.
#
# `permission:a|b` means "any of these", never "both", and a literal segment
# must be registered before the `{wildcard}` that would swallow it. Both traps
# are written up in `.ai/rules/routes.md`.

Route::middleware(['auth', 'verified'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        # -----------------------------------------------------------------
        # Visits
        # -----------------------------------------------------------------

        Route::controller(VisitController::class)
            ->prefix('visits')
            ->name('visits.')
            ->group(function () {
                Route::get('/', 'index')
                    ->middleware('permission:visits.view.any|visits.view.own')
                    ->name('index');

                Route::get('create', 'create')->middleware('permission:visits.create')->name('create');
                Route::get('export', 'export')->middleware('permission:visits.export')->name('export');
                Route::get('{visit}/edit', 'edit')->middleware('permission:visits.update')->name('edit');

                Route::post('/', 'store')->middleware('permission:visits.create')->name('store');
                Route::patch('{visit}', 'update')->middleware('permission:visits.update')->name('update');
                Route::delete('{visit}', 'destroy')->middleware('permission:visits.delete')->name('destroy');
            });

        # -----------------------------------------------------------------
        # Customers
        # -----------------------------------------------------------------

        Route::controller(CustomerController::class)
            ->prefix('customers')
            ->name('customers.')
            ->group(function () {
                Route::get('/', 'index')->middleware('permission:customers.view.any')->name('index');

                Route::get('create', 'create')->middleware('permission:customers.create')->name('create');
                Route::get('export', 'export')->middleware('permission:customers.export')->name('export');

                # Needs create and update too, which a pipe cannot say.
                # `CustomerPolicy` holds the conjunction.
                Route::post('import', 'import')
                    ->middleware('permission:customers.import')
                    ->name('import');
                Route::get('{customer}/edit', 'edit')->middleware('permission:customers.update')->name('edit');

                Route::post('/', 'store')->middleware('permission:customers.create')->name('store');
                Route::patch('{customer}', 'update')->middleware('permission:customers.update')->name('update');
                Route::delete('{customer}', 'destroy')->middleware('permission:customers.delete')->name('destroy');
            });

        # -----------------------------------------------------------------
        # Products
        # -----------------------------------------------------------------

        Route::controller(ProductController::class)
            ->prefix('products')
            ->name('products.')
            ->group(function () {
                Route::get('/', 'index')->middleware('permission:products.view.any')->name('index');

                # Both permissions, as two middleware rather than one pipe.
                # Written as a pipe this let somebody holding only one of them
                # past a gate the controller then closed.
                Route::post('sync', 'sync')
                    ->middleware(['permission:products.create', 'permission:products.update'])
                    ->name('sync');

                Route::get('create', 'create')->middleware('permission:products.create')->name('create');
                Route::get('{product}/edit', 'edit')->middleware('permission:products.update')->name('edit');

                Route::post('/', 'store')->middleware('permission:products.create')->name('store');

                # POST rather than PATCH: PHP cannot read a multipart body
                # from a PATCH, so the form spoofs the method and this answers
                # what the browser actually sends.
                Route::post('{product}', 'update')->middleware('permission:products.update')->name('update');

                Route::delete('{product}', 'destroy')->middleware('permission:products.delete')->name('destroy');
            });

        # -----------------------------------------------------------------
        # Roles and permissions
        # -----------------------------------------------------------------

        Route::controller(RoleController::class)->group(function () {
            Route::get('permissions', 'permissions')
                ->middleware('permission:roles.view')
                ->name('permissions.index');

            Route::prefix('roles')->name('roles.')->group(function () {
                Route::get('/', 'index')->middleware('permission:roles.view')->name('index');

                Route::get('create', 'create')->middleware('permission:roles.create')->name('create');
                Route::get('{role}/edit', 'edit')->middleware('permission:roles.view')->name('edit');

                Route::post('/', 'store')->middleware('permission:roles.create')->name('store');
                Route::patch('{role}', 'update')->middleware('permission:roles.update')->name('update');
                Route::delete('{role}', 'destroy')->middleware('permission:roles.delete')->name('destroy');
            });

            Route::patch('users/{user}/roles', 'assign')
                ->middleware('permission:roles.assign')
                ->name('users.roles.update');
        });

        # -----------------------------------------------------------------
        # Users
        # -----------------------------------------------------------------

        Route::controller(UserController::class)
            ->prefix('users')
            ->name('users.')
            ->group(function () {
                Route::get('create', 'create')->middleware('permission:users.create')->name('create');
                Route::post('/', 'store')->middleware('permission:users.create')->name('store');

                Route::middleware('permission:users.update')->group(function () {
                    Route::get('{user}/edit', 'edit')->name('edit');
                    Route::patch('{user}', 'update')->name('update');

                    # Its own address and confirmation: the one write here
                    # that cannot be undone by typing the old value back. Same
                    # permission, because anybody who can change the email a
                    # reset would go to already owns the account.
                    Route::put('{user}/password', 'password')->name('password.update');
                });

                # Its own permission: a direct grant shows up nowhere on the
                # Roles page, so it must not arrive with the right to correct
                # a spelling.
                Route::patch('{user}/permissions', 'permissions')
                    ->middleware('permission:users.permissions')
                    ->name('permissions.update');
            });

        # -----------------------------------------------------------------
        # Purchases
        # -----------------------------------------------------------------

        Route::controller(PurchaseController::class)
            ->prefix('purchases')
            ->name('purchases.')
            ->group(function () {
                Route::get('/', 'index')->middleware('permission:purchases.view.any')->name('index');

                Route::get('create', 'create')->middleware('permission:purchases.create')->name('create');
                Route::get('{purchase}/edit', 'edit')->middleware('permission:purchases.update')->name('edit');

                Route::post('/', 'store')->middleware('permission:purchases.create')->name('store');
                Route::patch('{purchase}', 'update')->middleware('permission:purchases.update')->name('update');
                Route::delete('{purchase}', 'destroy')->middleware('permission:purchases.delete')->name('destroy');
            });

        # -----------------------------------------------------------------
        # Reward campaigns
        # -----------------------------------------------------------------
        #
        # Overview, redemption, winners and catalogue are registered before the
        # campaign group, or `{campaign}` swallows them.

        Route::get('rewards/overview', [RewardOverviewController::class, 'index'])
            ->middleware('permission:rewards.view')
            ->name('rewards.overview.index');

        # The counter is a dialog on the winners list, which serves the lookup; only the
        # handover itself is posted, so there is no screen behind this name.
        Route::post('rewards/redeem', [RewardRedemptionController::class, 'store'])
            ->middleware('permission:rewards.redeem')
            ->name('rewards.redeem.store');

        Route::get('rewards/winners', [RewardWinnerController::class, 'index'])
            ->middleware('permission:rewards.view')
            ->name('rewards.winners.index');

        Route::controller(RewardController::class)
            ->prefix('rewards/catalogue')
            ->name('rewards.catalogue.')
            ->group(function () {
                Route::get('/', 'index')->middleware('permission:rewards.view')->name('index');

                Route::get('create', 'create')->middleware('permission:rewards.catalogue.create')->name('create');
                Route::post('/', 'store')->middleware('permission:rewards.catalogue.create')->name('store');

                Route::get('{reward}/edit', 'edit')->middleware('permission:rewards.view')->name('edit');

                Route::patch('{reward}', 'update')->middleware('permission:rewards.catalogue.update')->name('update');
                Route::delete('{reward}', 'destroy')->middleware('permission:rewards.catalogue.delete')->name('destroy');
            });

        Route::controller(RewardCampaignController::class)
            ->prefix('rewards')
            ->name('rewards.')
            ->group(function () {
                Route::get('/', 'index')->middleware('permission:rewards.view')->name('index');

                Route::get('create', 'create')->middleware('permission:rewards.campaigns.create')->name('create');
                Route::post('/', 'store')->middleware('permission:rewards.campaigns.create')->name('store');

                Route::get('{campaign}/edit', 'edit')->middleware('permission:rewards.view')->name('edit');

                Route::patch('{campaign}', 'update')->middleware('permission:rewards.campaigns.update')->name('update');

                Route::post('{campaign}/publish', 'publish')
                    ->middleware('permission:rewards.campaigns.update')
                    ->name('publish');

                Route::post('{campaign}/transition', 'transition')
                    ->middleware('permission:rewards.campaigns.update')
                    ->name('transition');

                Route::delete('{campaign}', 'destroy')->middleware('permission:rewards.campaigns.delete')->name('destroy');
            });

        # -----------------------------------------------------------------
        # Shuffle turns
        # -----------------------------------------------------------------

        Route::controller(ShuffleSessionController::class)
            ->name('shuffles.')
            ->group(function () {
                Route::post('purchases/{purchase}/shuffle', 'store')
                    ->middleware('permission:rewards.shuffle')
                    ->name('store');

                # Behind `rewards.view` rather than `rewards.shuffle`, so a
                # manager can look at a turn without being able to run it.
                Route::get('shuffles/{session}', 'show')
                    ->middleware('permission:rewards.view')
                    ->name('show');

                Route::post('shuffles/{session}/run', 'run')
                    ->middleware('permission:rewards.shuffle')
                    ->name('run');

                Route::delete('shuffles/{session}', 'destroy')
                    ->middleware('permission:rewards.shuffle')
                    ->name('destroy');
            });
    });

require __DIR__.'/settings.php';
