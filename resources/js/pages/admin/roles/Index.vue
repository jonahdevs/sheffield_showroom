<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Ellipsis,
    KeyRound,
    Lock,
    Pencil,
    Pin,
    Plus,
    Search,
    Shield,
    Trash2,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import PageSizeSelect from '@/components/PageSizeSelect.vue';
import SetPasswordDialog from '@/components/SetPasswordDialog.vue';
import type { PasswordSubject } from '@/components/SetPasswordDialog.vue';
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
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
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
import { confirmDelete, confirmDeleteChoosing } from '@/lib/confirm';
import { dashboard } from '@/routes';
import { create, destroy, edit, index } from '@/routes/admin/roles';
import { create as createUser, edit as editUser } from '@/routes/admin/users';
import { update as updateUserRoles } from '@/routes/admin/users/roles';

interface Paginated<T> extends Paginator {
    data: T[];
}

const props = defineProps<{
    roles: App.Data.RoleData[];
    /** Null for a viewer who may shape roles but not read the accounts in them. */
    holders: Paginated<App.Data.RoleHolderData> | null;
    filters: { search: string; role: string };
    page_sizes: number[];
    can: {
        create: boolean;
        update: boolean;
        delete: boolean;
        assign: boolean;
        view_users: boolean;
        create_users: boolean;
        update_users: boolean;
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
// Row actions
// -----------------------------------------------------------------------------

const settingPassword = ref<PasswordSubject | null>(null);

/*
 * Each item is asked about separately rather than the whole menu being hidden
 * on your own row. Correcting your own name is an ordinary thing to do, where
 * handing yourself a role and setting your own password without typing the old
 * one are both ways round a check - so the row keeps the first and drops the
 * other two.
 *
 * `is_manageable` is the server's answer for this row: it is false for an
 * account that can do more than the viewer can, so the menu never offers a
 * door the controller would shut.
 */
function canReRole(holder: App.Data.RoleHolderData): boolean {
    return props.can.assign && !holder.is_self;
}

function canSetPassword(holder: App.Data.RoleHolderData): boolean {
    return holder.is_manageable && !holder.is_self;
}

function hasActions(holder: App.Data.RoleHolderData): boolean {
    return holder.is_manageable || canReRole(holder);
}

// -----------------------------------------------------------------------------
// Deleting a role
// -----------------------------------------------------------------------------

/**
 * A role nobody holds is a plain confirmation. One with members has to say
 * where they go first: `RoleController::destroy` refuses to strand them.
 */
async function removeRole(role: App.Data.RoleData) {
    if (role.holders === 0) {
        if (!(await confirmDelete())) {
            return;
        }

        router.delete(destroy(role.id).url, { preserveScroll: true });

        return;
    }

    /* Never the role being removed: it is about to stop existing. */
    const elsewhere = props.roles.filter((option) => option.id !== role.id);

    const fallback = await confirmDeleteChoosing({
        text: 'Its members have to move to another role.',
        choices: Object.fromEntries(
            elsewhere.map((option) => [option.name, headline(option.name)]),
        ),
        selected: elsewhere[0]?.name,
        required: 'Choose the role its members should move to.',
    });

    if (fallback === null) {
        return;
    }

    router.delete(destroy(role.id).url, {
        data: { fallback },
        preserveScroll: true,
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
                        @click="removeRole(role)"
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
        <Card
            v-if="props.can.view_users && props.holders"
            class="min-w-0 gap-0 overflow-hidden p-0"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-3.5"
            >
                <h2 class="text-sm font-bold">Users</h2>

                <Button
                    v-if="props.can.create_users"
                    size="sm"
                    as-child
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

                                <!-- A capability pinned to the person rather
                                     than to a role appears nowhere else on
                                     this screen: take every role away and it
                                     stays. So the row says it, or the page
                                     would quietly lie about what somebody
                                     can do. -->
                                <Tooltip v-if="holder.direct_permissions > 0">
                                    <TooltipTrigger as-child>
                                        <Badge
                                            variant="outline"
                                            tabindex="0"
                                            :data-test="`direct-${holder.id}`"
                                        >
                                            <Pin class="size-3" />
                                            {{ holder.direct_permissions }}
                                            direct
                                        </Badge>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        {{ holder.direct_permissions }}
                                        {{
                                            holder.direct_permissions === 1
                                                ? 'permission is'
                                                : 'permissions are'
                                        }}
                                        granted to this account itself, not
                                        through a role
                                    </TooltipContent>
                                </Tooltip>
                            </div>

                            <div class="flex justify-end">
                                <DropdownMenu v-if="hasActions(holder)">
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
                                            v-if="holder.is_manageable"
                                            as-child
                                            :data-test="`edit-user-${holder.id}`"
                                        >
                                            <Link
                                                :href="editUser(holder.id).url"
                                            >
                                                <Pencil />
                                                Edit user
                                            </Link>
                                        </DropdownMenuItem>

                                        <DropdownMenuItem
                                            v-if="canReRole(holder)"
                                            :data-test="`assign-${holder.id}`"
                                            @select="openAssign(holder)"
                                        >
                                            <Shield />
                                            Change roles
                                        </DropdownMenuItem>

                                        <!-- Last, and separated: it is the one
                                             item here that cannot be undone by
                                             putting the old value back. -->
                                        <template v-if="canSetPassword(holder)">
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                :data-test="`set-password-${holder.id}`"
                                                @select="
                                                    settingPassword = {
                                                        id: holder.id,
                                                        name: holder.name,
                                                    }
                                                "
                                            >
                                                <KeyRound />
                                                Set password
                                            </DropdownMenuItem>
                                        </template>
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

    <SetPasswordDialog
        :subject="settingPassword"
        @close="settingPassword = null"
    />
</template>
