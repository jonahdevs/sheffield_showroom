<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Check, Minus } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { index, store, update } from '@/routes/admin/roles';

const props = defineProps<{
    role: App.Data.RoleData | null;
    matrix: App.Data.PermissionGroupData[];
    read_only: boolean;
}>();

const form = useForm<{
    name: string;
    description: string;
    permissions: string[];
}>({
    name: props.role?.name ?? '',
    description: props.role?.description ?? '',
    permissions: [...(props.role?.permissions ?? [])],
});

const heading = computed(() => props.role?.label ?? 'New role');

function toggle(permission: string, checked: boolean) {
    form.permissions = checked
        ? [...form.permissions, permission]
        : form.permissions.filter((value) => value !== permission);
}

function toggleGroup(group: App.Data.PermissionGroupData, checked: boolean) {
    const grantable = group.permissions
        .filter((permission) => permission.grantable)
        .map((permission) => permission.value);

    form.permissions = checked
        ? [...new Set([...form.permissions, ...grantable])]
        : form.permissions.filter((value) => !grantable.includes(value));
}

/**
 * Only grantable permissions count: a group holding one the viewer cannot
 * hand out would otherwise never read as fully ticked.
 */
function groupState(
    group: App.Data.PermissionGroupData,
): boolean | 'indeterminate' {
    const grantable = group.permissions.filter(
        (permission) => permission.grantable,
    );
    const held = grantable.filter((permission) =>
        form.permissions.includes(permission.value),
    );

    if (held.length === 0) {
        return false;
    }

    return held.length === grantable.length ? true : 'indeterminate';
}

function submit() {
    if (props.role) {
        form.patch(update(props.role.id).url);

        return;
    }

    form.post(store().url);
}

/** A callback rather than a static object: one component serves all three routes. */
defineOptions({
    layout: (page: { role: App.Data.RoleData | null; read_only: boolean }) => ({
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Roles', href: index().url },
            {
                title:
                    page.role === null
                        ? 'Create'
                        : page.read_only
                          ? 'View'
                          : 'Edit',
            },
        ],
    }),
});
</script>

<template>
    <Head :title="props.role ? heading : 'New role'" />

    <div class="flex flex-col gap-5">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl leading-tight">{{ heading }}</h1>
                <Badge v-if="props.role?.is_system" variant="secondary">
                    System
                </Badge>
            </div>

            <p class="mt-2 text-sm text-muted-foreground">
                {{
                    props.read_only
                        ? 'A role the application ships with. Its permissions are shown for reference and cannot be changed.'
                        : 'Name it after the job it does, then tick what that job needs. You can only hand out what you hold yourself.'
                }}
            </p>
        </div>

        <form class="flex flex-col gap-5" @submit.prevent="submit">
            <Card
                as="section"
                class="grid gap-3.5 sm:grid-cols-[220px_minmax(0,1fr)]"
            >
                <div class="grid gap-1.5">
                    <Label for="role-name">Name</Label>
                    <Input
                        id="role-name"
                        v-model="form.name"
                        :disabled="props.read_only"
                        placeholder="e.g. showroom-lead"
                        data-test="role-name"
                    />
                    <p v-if="form.errors.name" class="text-xs text-destructive">
                        {{ form.errors.name }}
                    </p>
                </div>

                <div class="grid gap-1.5">
                    <Label for="role-description">Description</Label>
                    <Input
                        id="role-description"
                        v-model="form.description"
                        :disabled="props.read_only"
                        placeholder="What this role is for."
                        data-test="role-description"
                    />
                    <p
                        v-if="form.errors.description"
                        class="text-xs text-destructive"
                    >
                        {{ form.errors.description }}
                    </p>
                </div>
            </Card>

            <Card as="section" class="gap-0 p-0">
                <div
                    class="flex items-center justify-between border-b border-border px-5 py-3.5"
                >
                    <h2
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        Permissions
                    </h2>
                    <span
                        class="text-xs text-faint tabular-nums"
                        data-test="permission-count"
                    >
                        {{ form.permissions.length }} selected
                    </span>
                </div>

                <div class="divide-y divide-border">
                    <div
                        v-for="group in props.matrix"
                        :key="group.group"
                        class="grid gap-2.5 px-5 py-3.5 sm:grid-cols-[170px_minmax(0,1fr)] sm:gap-4"
                    >
                        <label
                            class="flex items-center gap-2.5 text-xs font-bold"
                        >
                            <Checkbox
                                :model-value="groupState(group)"
                                :disabled="props.read_only"
                                :aria-label="`Select every ${group.group} permission`"
                                @update:model-value="
                                    toggleGroup(group, $event === true)
                                "
                            >
                                <Minus
                                    v-if="groupState(group) === 'indeterminate'"
                                    class="size-3.5"
                                />
                                <Check v-else class="size-3.5" />
                            </Checkbox>
                            {{ group.group_label }}
                        </label>

                        <div class="flex flex-wrap gap-x-6 gap-y-2">
                            <label
                                v-for="permission in group.permissions"
                                :key="permission.value"
                                class="flex items-center gap-2 text-xs"
                                :class="
                                    permission.grantable
                                        ? ''
                                        : 'text-muted-foreground'
                                "
                                :title="
                                    permission.grantable
                                        ? undefined
                                        : 'You do not hold this permission yourself.'
                                "
                            >
                                <Checkbox
                                    :model-value="
                                        form.permissions.includes(
                                            permission.value,
                                        )
                                    "
                                    :disabled="
                                        props.read_only || !permission.grantable
                                    "
                                    :data-test="`permission-${permission.value}`"
                                    @update:model-value="
                                        toggle(
                                            permission.value,
                                            $event === true,
                                        )
                                    "
                                />
                                {{ permission.label }}
                            </label>
                        </div>
                    </div>
                </div>
            </Card>

            <p
                v-if="form.errors.permissions"
                class="text-xs text-destructive"
                data-test="permission-error"
            >
                {{ form.errors.permissions }}
            </p>

            <div class="flex items-center justify-end gap-3">
                <Button as-child variant="ghost">
                    <Link :href="index().url">
                        {{ props.read_only ? 'Close' : 'Cancel' }}
                    </Link>
                </Button>
                <Button
                    v-if="!props.read_only"
                    type="submit"
                    :disabled="form.processing || form.name === ''"
                    data-test="save-role"
                >
                    {{ props.role ? 'Save changes' : 'Create role' }}
                </Button>
            </div>
        </form>
    </div>
</template>
