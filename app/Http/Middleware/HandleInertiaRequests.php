<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
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

                # For hiding controls only. Authorisation still happens on the
                # server; this never gates anything on its own.
                'permissions' => $this->permissions($request),

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
