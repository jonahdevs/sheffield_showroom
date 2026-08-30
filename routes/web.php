<?php

use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VisitController;
use App\Http\Controllers\DashboardController;
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
                   needs the permissions for both. */
                Route::post('sync', 'sync')
                    ->middleware('permission:products.create|products.update')
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

            Route::patch('users/{user}/roles', 'assign')
                ->middleware('permission:roles.assign')
                ->name('users.roles.update');
        });

        // ---------------------------------------------------------------
        // Users
        // ---------------------------------------------------------------

        /* No index of its own: the Roles screen already lists every account
           with the roles it holds, and these are the two links off it. */
        Route::controller(UserController::class)
            ->prefix('users')
            ->name('users.')
            ->group(function () {
                /* `create` before `{user}`, or the wildcard swallows it. */
                Route::get('create', 'create')->middleware('permission:users.create')->name('create');
                Route::get('{user}/edit', 'edit')->middleware('permission:users.update')->name('edit');

                Route::post('/', 'store')->middleware('permission:users.create')->name('store');
                Route::patch('{user}', 'update')->middleware('permission:users.update')->name('update');
            });
    });

require __DIR__.'/settings.php';
