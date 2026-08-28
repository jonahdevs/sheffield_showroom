<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),

                /* What the viewer holds, so the sidebar and a page's controls
                   can hide what the route would refuse anyway. Authorisation
                   still happens on the server; this only stops the interface
                   offering a door that is locked. */
                'permissions' => $this->permissions($request),

                /* The account menu wears the role rather than the account
                   type: "Super Admin" says something the word "user" cannot. */
                'roles' => $request->user()?->getRoleNames()->all() ?? [],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function permissions(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        return array_values(array_filter(
            Permission::values(),
            fn (string $permission) => $user->can($permission),
        ));
    }
}
