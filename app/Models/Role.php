<?php

declare(strict_types=1);

namespace App\Models;

use App\Policies\RolePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property bool $is_system
 * @property string|null $description
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[UsePolicy(RolePolicy::class)]
class Role extends SpatieRole
{
    protected $fillable = [
        'name',
        'guard_name',
        'is_system',
        'description',
    ];

    public const SYSTEM_ROLES = [
        self::SUPER_ADMIN,
    ];

    public const SUPER_ADMIN = 'super-admin';

    # Named rather than derived: `VisitReport::forViewer()` reads it because
    # a receptionist's export is narrower than their permissions are.
    public const RECEPTION = 'reception';

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function system(Builder $query): void
    {
        $query->where('is_system', true);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function custom(Builder $query): void
    {
        $query->where('is_system', false);
    }

    public function label(): string
    {
        return Str::headline($this->name);
    }

    public function isSuperAdmin(): bool
    {
        return $this->name === self::SUPER_ADMIN;
    }

    public function isEditable(): bool
    {
        return ! $this->is_system;
    }
}
