<?php

use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\VisitController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
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

                /* `create` before `{visit}`, or the wildcard swallows it. */
                Route::get('create', 'create')->middleware('permission:visits.create')->name('create');
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

                /* `create` before `{customer}`, or the wildcard swallows it. */
                Route::get('create', 'create')->middleware('permission:customers.create')->name('create');
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
    });

require __DIR__.'/settings.php';
