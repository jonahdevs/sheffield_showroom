<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Ellipsis,
    KeyRound,
    Lock,
    Plus,
    Search,
    Shield,
    SquarePen,
    Trash2,
    Users,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import PageSizeSelect from '@/components/PageSizeSelect.vue';
import TablePagination from '@/components/TablePagination.vue';
import type { Paginator } from '@/components/TablePagination.vue';
import TableSkeleton from '@/components/TableSkeleton.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useFilters } from '@/composables/useFilters';
import { useInitials } from '@/composables/useInitials';
import { dashboard } from '@/routes';
import { create, destroy, edit, index } from '@/routes/admin/roles';
import { create as createUser, edit as editUser } from '@/routes/admin/users';
import { update as updateUserRoles } from '@/routes/admin/users/roles';

interface Paginated<T> extends Paginator {
    data: T[];
}

const props = defineProps<{
    roles: App.Data.RoleData[];
    holders: Paginated<App.Data.RoleHolderData>;
    filters: { search: string; role: string };
    page_sizes: number[];
    can: {
        create: boolean;
        update: boolean;
        delete: boolean;
        assign: boolean;
        create_user: boolean;
        update_user: boolean;
    };
}>();

const { getInitials } = useInitials();

const { filters, hasFilters, processing, clear } = useFilters({
    url: index.url(),
    initial: {
        search: props.filters.search ?? '',
        role: props.filters.role === '' ? 'all' : props.filters.role,
    },
    blank: { search: '', role: 'all' },
    only: ['holders', 'filters'],
});

const activeFilterCount = computed(
    () => (filters.search === '' ? 0 : 1) + (filters.role === 'all' ? 0 : 1),
);

function headline(name: string): string {
    return name.replace(/-/g, ' ');
}

/** Whether the row's menu has anything in it worth opening. */
function canActOn(holder: App.Data.RoleHolderData): boolean {
    return props.can.update_user || (props.can.assign && !holder.is_self);
}

// -----------------------------------------------------------------------------
// Assigning roles
// -----------------------------------------------------------------------------

const assigning = ref<App.Data.RoleHolderData | null>(null);

const assignForm = useForm<{ roles: string[] }>({ roles: [] });

/** Only roles the viewer could have built themselves are worth offering. */
const assignableRoles = computed(() => props.roles);

function openAssign(holder: App.Data.RoleHolderData) {
    assigning.value = holder;
    assignForm.clearErrors();
    assignForm.roles = [...holder.roles];
}

function toggleRole(name: string, checked: boolean) {
    assignForm.roles = checked
        ? [...new Set([...assignForm.roles, name])]
        : assignForm.roles.filter((role) => role !== name);
}

function submitAssign() {
    if (assigning.value === null) {
        return;
    }

    assignForm.patch(updateUserRoles(assigning.value.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            assigning.value = null;
        },
    });
}

// -----------------------------------------------------------------------------
// Deleting a role
// -----------------------------------------------------------------------------

const deleting = ref<App.Data.RoleData | null>(null);

const fallback = ref('');

const fallbackError = ref('');

/** Where this role's holders would land. Never the role being removed. */
const fallbackOptions = computed(() =>
    props.roles.filter((role) => role.id !== deleting.value?.id),
);

watch(deleting, (role) => {
    fallback.value = '';
    fallbackError.value = '';

    if (role && role.holders > 0) {
        fallback.value = fallbackOptions.value[0]?.name ?? '';
    }
});

function confirmDelete() {
    const role = deleting.value;

    if (role === null) {
        return;
    }

    router.delete(destroy(role.id).url, {
        data: { fallback: fallback.value },
        preserveScroll: true,
        onSuccess: () => {
            deleting.value = null;
        },
        onError: (errors) => {
            fallbackError.value = errors.fallback ?? '';
        },
    });
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Roles' },
        ],
    },
});
</script>

<!--
  The roles this showroom hands out, and the people holding them. What a role
  can actually do is edited one screen along, on the permission matrix - this
  page is about which roles exist and who is in them.
-->
<template>
    <Head title="Roles" />

    <div class="flex flex-col gap-5">
        <div class="flex flex-wrap items-start gap-4">
            <div class="flex min-w-0 flex-1 flex-col gap-2">
                <h1 class="text-2xl leading-tight">Roles</h1>
                <p class="text-sm text-muted-foreground">
                    A role is a named bundle of permissions. Give somebody the
                    job, not the checklist.
                </p>
            </div>

            <Button v-if="props.can.create" as-child data-test="new-role">
                <Link :href="create().url">
                    <Plus />
                    New role
                </Link>
            </Button>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            <Card
                v-for="role in props.roles"
                :key="role.id"
                :data-test="`role-${role.name}`"
            >
                <div class="flex items-start gap-3">
                    <h2 class="min-w-0 flex-1 text-sm capitalize">
                        {{ headline(role.name) }}
                    </h2>

                    <!-- A role with nobody in it wears no face. -->
                    <Avatar
                        v-if="role.first_holder_name"
                        class="size-8 shrink-0"
                        :title="`${headline(role.name)} is held by ${role.first_holder_name}`"
                    >
                        <AvatarFallback class="text-xs">
                            {{ getInitials(role.first_holder_name) }}
                        </AvatarFallback>
                    </Avatar>
                </div>

                <dl class="mt-4 mb-5 flex flex-col gap-2 text-xs text-faint">
                    <div class="flex items-center gap-2">
                        <KeyRound class="size-4 shrink-0" aria-hidden="true" />
                        <dt class="sr-only">Permissions</dt>
                        <dd>
                            {{ role.permissions.length }}
                            {{
                                role.permissions.length === 1
                                    ? 'permission'
                                    : 'permissions'
                            }}
                        </dd>
                    </div>
                    <div class="flex items-center gap-2">
                        <Users class="size-4 shrink-0" aria-hidden="true" />
                        <dt class="sr-only">Members</dt>
                        <dd>
                            {{ role.holders }}
                            {{ role.holders === 1 ? 'member' : 'members' }}
                        </dd>
                    </div>
                </dl>

                <div
                    class="mt-auto flex items-center justify-between border-t border-divider pt-3.5"
                >
                    <Link
                        :href="edit(role.id).url"
                        class="text-xs font-bold text-primary underline-offset-4 hover:underline"
                        :data-test="`edit-${role.name}`"
                    >
                        {{
                            role.is_system || !props.can.update
                                ? 'View permissions'
                                : 'Edit role'
                        }}
                    </Link>

                    <!-- A badge by the name would say this twice; the lock
                         says it where the delete button would otherwise be,
                         and explains itself on hover. -->
                    <Tooltip v-if="role.is_system">
                        <TooltipTrigger as-child>
                            <span
                                class="cursor-default"
                                tabindex="0"
                                :aria-label="`${headline(role.name)} is a protected role`"
                                :data-test="`protected-${role.name}`"
                            >
                                <Lock class="size-4 text-faint" />
                            </span>
                        </TooltipTrigger>
                        <TooltipContent>System role</TooltipContent>
                    </Tooltip>
                    <Button
                        v-else-if="props.can.delete"
                        variant="quiet"
                        size="icon"
                        class="size-8.5 rounded-[9px]"
                        :aria-label="`Delete the ${headline(role.name)} role`"
                        :data-test="`delete-${role.name}`"
                        @click="deleting = role"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>
            </Card>
        </div>

        <!--
          Who holds what. Roles mean nothing until somebody is in one, so the
          list of people sits under the list of roles rather than a page away.
        -->
        <Card class="min-w-0 gap-0 overflow-hidden p-0">
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-3.5"
            >
                <h2 class="text-sm font-bold">Users</h2>

                <!-- The account itself, not the role on it: somebody who is
                     not on this list yet has nothing to be assigned. -->
                <Button
                    v-if="props.can.create_user"
                    as-child
                    size="sm"
                    variant="quiet"
                    data-test="new-user"
                >
                    <Link :href="createUser().url">
                        <Plus />
                        New user
                    </Link>
                </Button>
            </div>

            <div
                class="flex flex-wrap items-center gap-2.5 border-b border-border px-5 py-3.5"
            >
                <InputGroup class="w-full max-w-72">
                    <InputGroupInput
                        v-model="filters.search"
                        type="search"
                        placeholder="Search name or email..."
                        aria-label="Search users"
                        data-test="holder-search"
                    />

                    <InputGroupAddon>
                        <Search />
                    </InputGroupAddon>
                </InputGroup>

                <div class="flex flex-wrap items-center gap-2.5 sm:ml-auto">
                    <Select v-model="filters.role">
                        <SelectTrigger
                            class="w-44"
                            aria-label="Filter by role"
                            data-test="holder-role"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All roles</SelectItem>
                            <SelectItem value="none">No role</SelectItem>
                            <SelectItem
                                v-for="role in props.roles"
                                :key="role.id"
                                :value="role.name"
                                class="capitalize"
                            >
                                {{ headline(role.name) }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Button
                        v-if="hasFilters"
                        variant="link"
                        size="sm"
                        class="px-1"
                        data-test="holder-clear"
                        @click="clear"
                    >
                        Clear
                        <span class="text-muted-foreground">
                            ({{ activeFilterCount }})
                        </span>
                    </Button>
                </div>
            </div>

            <!-- Ahead of the empty branch on purpose: mid-reload nobody knows
                 what is coming back, and flashing "nobody matches" over rows
                 that are about to land reads as broken. -->
            <TableSkeleton
                v-if="processing"
                class="px-5 py-2"
                :rows="Math.min(props.holders.per_page, 10)"
                :columns="3"
            />

            <p
                v-else-if="props.holders.data.length === 0"
                class="m-5 rounded-lg bg-muted/50 p-4 text-sm text-muted-foreground"
                data-test="holders-empty"
            >
                {{
                    hasFilters
                        ? 'Nobody matches that search.'
                        : 'There are no users yet.'
                }}
            </p>

            <template v-else>
                <div class="overflow-x-auto">
                    <!-- Semibold rather than bold: the lead cell of every row below
                         is itself text-xs font-bold, so a bold header would read as
                         one more row instead of the label for all of them. -->
                    <div
                        class="grid min-w-[680px] grid-cols-[minmax(0,1fr)_minmax(0,1fr)_56px] items-center gap-4 border-b border-border bg-muted/50 px-5 py-3.5 text-xs font-semibold"
                    >
                        <span>User</span>
                        <span>Roles</span>
                        <span class="sr-only">Actions</span>
                    </div>

                    <div class="divide-y divide-border">
                        <div
                            v-for="holder in props.holders.data"
                            :key="holder.id"
                            class="grid min-w-[680px] grid-cols-[minmax(0,1fr)_minmax(0,1fr)_56px] items-center gap-4 px-5 py-3.5"
                            :data-test="`holder-${holder.id}`"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <Avatar class="size-8 rounded-lg">
                                    <AvatarFallback
                                        class="rounded-lg text-xs text-black dark:text-white"
                                    >
                                        {{ getInitials(holder.name) }}
                                    </AvatarFallback>
                                </Avatar>

                                <div class="min-w-0">
                                    <p class="truncate text-xs font-bold">
                                        {{ holder.name }}
                                        <span
                                            v-if="holder.is_self"
                                            class="font-normal text-muted-foreground"
                                        >
                                            (you)
                                        </span>
                                    </p>
                                    <p
                                        class="mt-0.5 truncate text-xs text-faint"
                                    >
                                        {{ holder.email }}
                                    </p>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-1.5">
                                <Badge
                                    v-for="role in holder.roles"
                                    :key="role"
                                    variant="secondary"
                                    class="capitalize"
                                >
                                    {{ headline(role) }}
                                </Badge>
                                <span
                                    v-if="holder.roles.length === 0"
                                    class="text-xs text-muted-foreground"
                                    :data-test="`holder-${holder.id}-no-role`"
                                >
                                    No role
                                </span>
                            </div>

                            <!-- Nobody re-roles themselves, so on your own row
                                 the menu is whatever is left - which is the
                                 account itself, and nothing at all without the
                                 permission to edit one. -->
                            <div class="flex justify-end">
                                <DropdownMenu v-if="canActOn(holder)">
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon-sm"
                                            :aria-label="`Actions for ${holder.name}`"
                                            :data-test="`actions-${holder.id}`"
                                        >
                                            <Ellipsis />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem
                                            v-if="props.can.update_user"
                                            as-child
                                            :data-test="`edit-user-${holder.id}`"
                                        >
                                            <Link
                                                class="w-full cursor-pointer"
                                                :href="editUser(holder.id).url"
                                            >
                                                <SquarePen />
                                                Edit user
                                            </Link>
                                        </DropdownMenuItem>

                                        <DropdownMenuItem
                                            v-if="
                                                props.can.assign &&
                                                !holder.is_self
                                            "
                                            :data-test="`assign-${holder.id}`"
                                            @select="openAssign(holder)"
                                        >
                                            <Shield />
                                            Change roles
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </div>
                    </div>
                </div>

                <TablePagination
                    class="border-t border-border px-5 py-4"
                    :meta="props.holders"
                    label="users"
                >
                    <PageSizeSelect
                        :sizes="props.page_sizes"
                        :current="props.holders.per_page"
                    />
                </TablePagination>
            </template>
        </Card>
    </div>

    <Dialog
        :open="assigning !== null"
        @update:open="!$event && (assigning = null)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Change roles</DialogTitle>
                <DialogDescription>
                    What {{ assigning?.name }} may do here. Everything they hold
                    is the sum of every role ticked.
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-col gap-2.5">
                <label
                    v-for="role in assignableRoles"
                    :key="role.id"
                    class="flex items-start gap-2.5 rounded-lg border border-border p-3"
                >
                    <Checkbox
                        :model-value="assignForm.roles.includes(role.name)"
                        class="mt-0.5"
                        :data-test="`assign-role-${role.name}`"
                        @update:model-value="
                            toggleRole(role.name, $event === true)
                        "
                    />
                    <span class="min-w-0">
                        <span class="block text-xs font-bold capitalize">
                            {{ headline(role.name) }}
                        </span>
                        <span
                            class="mt-0.5 block text-xs text-muted-foreground"
                        >
                            {{ role.description ?? 'No description.' }}
                        </span>
                    </span>
                </label>
            </div>

            <p
                v-if="assignForm.errors.roles"
                class="text-xs text-destructive"
                data-test="assign-error"
            >
                {{ assignForm.errors.roles }}
            </p>

            <DialogFooter>
                <Button variant="ghost" @click="assigning = null">
                    Cancel
                </Button>
                <Button
                    :disabled="assignForm.processing"
                    data-test="confirm-assign"
                    @click="submitAssign"
                >
                    Save roles
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="deleting !== null"
        @update:open="!$event && (deleting = null)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle class="capitalize">
                    Delete {{ headline(deleting?.name ?? '') }}?
                </DialogTitle>
                <DialogDescription>
                    <template v-if="(deleting?.holders ?? 0) > 0">
                        {{ deleting?.holders }}
                        {{
                            deleting?.holders === 1
                                ? 'person holds'
                                : 'people hold'
                        }}
                        this role. Choose where they move to - an account left
                        with no role keeps its login and loses every ability on
                        it.
                    </template>
                    <template v-else>
                        Nobody holds this role, so nothing moves. This cannot be
                        undone.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <div v-if="(deleting?.holders ?? 0) > 0" class="grid gap-1.5">
                <Label for="role-fallback">Move members to</Label>
                <Select v-model="fallback">
                    <SelectTrigger
                        id="role-fallback"
                        class="w-full"
                        data-test="role-fallback"
                    >
                        <SelectValue placeholder="Choose a role" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in fallbackOptions"
                            :key="option.id"
                            :value="option.name"
                            class="capitalize"
                        >
                            {{ headline(option.name) }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <p v-if="fallbackError" class="text-xs text-destructive">
                    {{ fallbackError }}
                </p>
            </div>

            <DialogFooter>
                <Button variant="ghost" @click="deleting = null">Cancel</Button>
                <Button
                    variant="destructive"
                    data-test="confirm-delete-role"
                    @click="confirmDelete"
                >
                    Delete role
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
