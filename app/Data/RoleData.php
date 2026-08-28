<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Role;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript(location: ['App', 'Data'])]
class RoleData extends Data
{
    /**
     * @param  array<int, string>  $permissions
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $label,
        public ?string $description,
        public bool $is_system,
        public int $holders,
        public array $permissions,
        /** The face the card puts on the role: its first holder, if it has one. */
        public ?string $first_holder_name = null,
    ) {}

    public static function fromModel(Role $role): self
    {
        /* Only present when the caller eager-loaded them; every other reader
           of this object wants the count alone. */
        $first = $role->relationLoaded('users') ? $role->users->first() : null;

        return new self(
            id: $role->id,
            name: $role->name,
            label: $role->label(),
            description: $role->description,
            is_system: $role->is_system,
            holders: $role->users_count ?? $role->users()->count(),
            permissions: $role->permissions->pluck('name')->all(),
            first_holder_name: $first?->getAttribute('name'),
        );
    }
}
