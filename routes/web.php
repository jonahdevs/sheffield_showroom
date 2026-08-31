<?php

use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\PurchaseController;
use App\Http\Controllers\Admin\RewardCampaignController;
use App\Http\Controllers\Admin\RewardRedemptionController;
use App\Http\Controllers\Admin\RewardWinnerController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\ShuffleSessionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VisitController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ShuffleController;
use Illuminate\Support\Facades\Route;

/* There is nothing here for a visitor to read: every screen in the showroom
   is behind a sign-in, so the root is a door rather than a page. It stays
   named `home` because Fortify sends people here after logging out and after
   deleting an account, and those redirects resolve the name, not the path.

   A signed-in person is deliberately not special-cased. `/login` already wears
   the `guest` middleware, which bounces anyone authenticated to the dashboard,
   so routing them straight there from here would be a second copy of that
   decision - and the copy is the one that would rot when the destination
   moves. */
Route::redirect('/', '/login')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::controller(DashboardController::class)
        ->middleware('permission:dashboard.view')
        ->group(function () {
            Route::get('dashboard', 'index')->name('dashboard');

            /* The same figures as a file. No permission of its own: it is a
               copy of the screen the line above already opened. */
            Route::get('dashboard/export', 'export')->name('dashboard.export');
        });
});

/*
|--------------------------------------------------------------------------
| The customer shuffle
|--------------------------------------------------------------------------
|
| The only pages in the showroom with no sign-in behind them, and the only
| reason the rule at the top of this file has an exception: a customer has no
| account, and asking them to make one at a counter would be the shortest way
| to stop anybody ever using this.
|
| The token in the URL is the whole of the authorisation. It is 64 random
| characters, it names nothing - no customer, no purchase, nothing sequential -
| and it is good for one reward and one day. The throttle is what turns
| "guessing is not a strategy" into "guessing is not worth attempting"; it is
| keyed by IP because there is nobody signed in to key it by.
|
*/

Route::middleware('throttle:shuffle')
    ->prefix('rewards/shuffle')
    ->name('rewards.shuffle.')
    ->controller(ShuffleController::class)
    ->group(function () {
        Route::get('{token}', 'show')->name('show');
        Route::post('{token}', 'store')->name('store');
    });

/*
|--------------------------------------------------------------------------
| Administration
|--------------------------------------------------------------------------
|
| Every route names the capability it needs, so the gate is readable here
| rather than only inside the controller.
|
*/

Route::middleware(['auth', 'verified'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        // ---------------------------------------------------------------
        // Visits
        // ---------------------------------------------------------------

        Route::controller(VisitController::class)
            ->prefix('visits')
            ->name('visits.')
            ->group(function () {
                /* Either half of the split opens the list; which visits it
                   then holds is the controller's business. */
                Route::get('/', 'index')
                    ->middleware('permission:visits.view.any|visits.view.own')
                    ->name('index');

                /* `create` and `export` before `{visit}`, or the wildcard
                   swallows them. */
                Route::get('create', 'create')->middleware('permission:visits.create')->name('create');
                Route::get('export', 'export')->middleware('permission:visits.export')->name('export');
                Route::get('{visit}/edit', 'edit')->middleware('permission:visits.update')->name('edit');

                Route::post('/', 'store')->middleware('permission:visits.create')->name('store');
                Route::patch('{visit}', 'update')->middleware('permission:visits.update')->name('update');
                Route::delete('{visit}', 'destroy')->middleware('permission:visits.delete')->name('destroy');
            });

        // ---------------------------------------------------------------
        // Customers
        // ---------------------------------------------------------------

        Route::controller(CustomerController::class)
            ->prefix('customers')
            ->name('customers.')
            ->group(function () {
                Route::get('/', 'index')->middleware('permission:customers.view.any')->name('index');

                /* `create` and `export` before `{customer}`, or the wildcard
                   swallows them. */
                Route::get('create', 'create')->middleware('permission:customers.create')->name('create');
                Route::get('export', 'export')->middleware('permission:customers.export')->name('export');

                /* Only the import permission is named here. A file of
                   customers both adds and rewrites rows, so it needs the
                   permissions for both as well - but `permission:` reads a
                   pipe as "any of these", and stating the conjunction here
                   would say the opposite of what it means. `CustomerPolicy`
                   holds it instead. */
                Route::post('import', 'import')
                    ->middleware('permission:customers.import')
                    ->name('import');
                Route::get('{customer}/edit', 'edit')->middleware('permission:customers.update')->name('edit');

                Route::post('/', 'store')->middleware('permission:customers.create')->name('store');
                Route::patch('{customer}', 'update')->middleware('permission:customers.update')->name('update');
                Route::delete('{customer}', 'destroy')->middleware('permission:customers.delete')->name('destroy');
            });

        // ---------------------------------------------------------------
        // Products
        // ---------------------------------------------------------------

        Route::controller(ProductController::class)
            ->prefix('products')
            ->name('products.')
            ->group(function () {
                Route::get('/', 'index')->middleware('permission:products.view.any')->name('index');

                /* Pulling the catalogue both adds and rewrites rows, so it
                   needs the permissions for both - and `permission:` reads a
                   pipe as "any of these", so the conjunction is two middleware
                   in a row rather than one directive. Written as a pipe this
                   said the opposite of what it meant, and let somebody holding
                   only one of the two past a gate the controller then closed.
                   `ProductController::sync` still authorizes both. */
                Route::post('sync', 'sync')
                    ->middleware(['permission:products.create', 'permission:products.update'])
                    ->name('sync');

                /* `create` before `{product}`, or the wildcard swallows it. */
                Route::get('create', 'create')->middleware('permission:products.create')->name('create');
                Route::get('{product}/edit', 'edit')->middleware('permission:products.update')->name('edit');

                Route::post('/', 'store')->middleware('permission:products.create')->name('store');

                /* POST rather than PATCH: a multipart body carrying a file
                   cannot be read from a PATCH in PHP, so the form spoofs the
                   method and this route answers what the browser sends. */
                Route::post('{product}', 'update')->middleware('permission:products.update')->name('update');

                Route::delete('{product}', 'destroy')->middleware('permission:products.delete')->name('destroy');
            });

        // ---------------------------------------------------------------
        // Roles and permissions
        // ---------------------------------------------------------------

        Route::controller(RoleController::class)->group(function () {
            Route::get('permissions', 'permissions')
                ->middleware('permission:roles.view')
                ->name('permissions.index');

            Route::prefix('roles')->name('roles.')->group(function () {
                Route::get('/', 'index')->middleware('permission:roles.view')->name('index');

                /* `create` before `{role}`, or the wildcard swallows it. */
                Route::get('create', 'create')->middleware('permission:roles.create')->name('create');
                Route::get('{role}/edit', 'edit')->middleware('permission:roles.view')->name('edit');

                Route::post('/', 'store')->middleware('permission:roles.create')->name('store');
                Route::patch('{role}', 'update')->middleware('permission:roles.update')->name('update');
                Route::delete('{role}', 'destroy')->middleware('permission:roles.delete')->name('destroy');
            });

            /* Staffing a role stays with the roles controller and keeps its
               address under `users`, because that is where the screen puts it:
               the list of people sits on the Roles page, under the roles they
               hold. Registered before the users group below only so the two
               halves of `admin.users.*` read together; `{user}/roles` is
               literal past the wildcard either way. */
            Route::patch('users/{user}/roles', 'assign')
                ->middleware('permission:roles.assign')
                ->name('users.roles.update');
        });

        // ---------------------------------------------------------------
        // Users
        // ---------------------------------------------------------------

        Route::controller(UserController::class)
            ->prefix('users')
            ->name('users.')
            ->group(function () {
                /* `create` before `{user}`, or the wildcard swallows it. */
                Route::get('create', 'create')->middleware('permission:users.create')->name('create');
                Route::post('/', 'store')->middleware('permission:users.create')->name('store');

                Route::middleware('permission:users.update')->group(function () {
                    Route::get('{user}/edit', 'edit')->name('edit');
                    Route::patch('{user}', 'update')->name('update');

                    /* Setting a password is not a field on the form above: it
                       is the one write here that cannot be undone by typing
                       the old value back, so it gets its own address, its own
                       request and its own confirmation. The permission is the
                       same one, because anybody who can change the email a
                       reset would go to already owns the account. */
                    Route::put('{user}/password', 'password')->name('password.update');
                });

                /* Its own permission rather than `users.update`. A direct
                   grant is the one thing on this screen that shows up nowhere
                   on the Roles page, so it is handed out deliberately instead
                   of arriving with the right to correct a spelling. */
                Route::patch('{user}/permissions', 'permissions')
                    ->middleware('permission:users.permissions')
                    ->name('permissions.update');
            });

        // ---------------------------------------------------------------
        // Purchases
        // ---------------------------------------------------------------

        Route::controller(PurchaseController::class)
            ->prefix('purchases')
            ->name('purchases.')
            ->group(function () {
                Route::get('/', 'index')->middleware('permission:purchases.view.any')->name('index');

                /* `create` before `{purchase}`, or the wildcard swallows it. */
                Route::get('create', 'create')->middleware('permission:purchases.create')->name('create');
                Route::get('{purchase}/edit', 'edit')->middleware('permission:purchases.update')->name('edit');

                Route::post('/', 'store')->middleware('permission:purchases.create')->name('store');
                Route::patch('{purchase}', 'update')->middleware('permission:purchases.update')->name('update');
                Route::delete('{purchase}', 'destroy')->middleware('permission:purchases.delete')->name('destroy');
            });

        // ---------------------------------------------------------------
        // Reward campaigns
        // ---------------------------------------------------------------

        /* Redemption first: its path would otherwise fall under the
           `{campaign}` wildcard registered below. */
        Route::controller(RewardRedemptionController::class)
            ->prefix('rewards/redeem')
            ->name('rewards.redeem.')
            ->group(function () {
                Route::get('/', 'index')->middleware('permission:rewards.view')->name('index');
                Route::post('/', 'store')->middleware('permission:rewards.redeem')->name('store');
            });

        /* Ahead of `{campaign}` for the same reason. Read-only, so it needs
           nothing beyond `rewards.view` - handing a reward over is Redeem's
           door and carries its own permission. */
        Route::get('rewards/winners', [RewardWinnerController::class, 'index'])
            ->middleware('permission:rewards.view')
            ->name('rewards.winners.index');

        Route::controller(RewardCampaignController::class)
            ->prefix('rewards')
            ->name('rewards.')
            ->group(function () {
                Route::get('/', 'index')->middleware('permission:rewards.view')->name('index');

                /* `create` before `{campaign}`, or the wildcard swallows it. */
                Route::get('create', 'create')->middleware('permission:rewards.campaigns.create')->name('create');
                Route::post('/', 'store')->middleware('permission:rewards.campaigns.create')->name('store');

                /* Reading a campaign needs only `rewards.view`; the form opens
                   read-only without the rest, the same way a system role
                   does. */
                Route::get('{campaign}/edit', 'edit')->middleware('permission:rewards.view')->name('edit');

                Route::patch('{campaign}', 'update')->middleware('permission:rewards.campaigns.update')->name('update');

                /* One-way, and the only action here that cannot be undone. */
                Route::post('{campaign}/publish', 'publish')
                    ->middleware('permission:rewards.campaigns.update')
                    ->name('publish');

                Route::post('{campaign}/transition', 'transition')
                    ->middleware('permission:rewards.campaigns.update')
                    ->name('transition');

                Route::delete('{campaign}', 'destroy')->middleware('permission:rewards.campaigns.delete')->name('destroy');
            });

        // ---------------------------------------------------------------
        // Shuffle turns
        // ---------------------------------------------------------------

        Route::controller(ShuffleSessionController::class)
            ->name('shuffles.')
            ->group(function () {
                /* Minting hangs off the purchase, because that is what earns
                   it - one turn per sale, held by a unique index. */
                Route::post('purchases/{purchase}/shuffle', 'store')
                    ->middleware('permission:rewards.shuffle')
                    ->name('store');

                /* The QR screen. Behind `rewards.view` rather than
                   `rewards.shuffle`, so a manager can look at a turn without
                   being able to run it. */
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
