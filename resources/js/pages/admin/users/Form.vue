<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Check, KeyRound, Minus, Pin, Shield } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import OptionMultiCombobox from '@/components/OptionMultiCombobox.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import SetPasswordDialog from '@/components/SetPasswordDialog.vue';
import type { PasswordSubject } from '@/components/SetPasswordDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { index as rolesIndex } from '@/routes/admin/roles';
import { store, update } from '@/routes/admin/users';
import { update as updatePermissions } from '@/routes/admin/users/permissions';
import { update as updateRoles } from '@/routes/admin/users/roles';

const props = defineProps<{
    user: App.Data.UserFormData | null;
    matrix: App.Data.PermissionGroupData[];
    /** Permission value to the roles handing this account that permission. */
    inherited: Record<string, string[]>;
    roles: App.Data.RoleData[];
    /** Role name to everything holding it grants, super admin spelled out. */
    role_grants: Record<string, string[]>;
    can: {
        assign_roles: boolean;
        permissions: boolean;
        password: boolean;
    };
}>();

const heading = computed(() => props.user?.name ?? 'New user');

function headline(name: string): string {
    return name.replace(/-/g, ' ');
}

// =========================================================================
// The account itself
// =========================================================================

/* Password fields only exist while creating. Afterwards the password has its
   own action behind its own permission. */
const details = useForm({
    name: props.user?.name ?? '',
    email: props.user?.email ?? '',
    password: '',
    password_confirmation: '',
});

const canSaveDetails = computed(() => {
    if (details.processing || details.name.trim() === '') {
        return false;
    }

    return props.user !== null || details.password !== '';
});

// =========================================================================
// The roles the account holds
// =========================================================================

/* On create these ride along in the details payload; on edit they are their own
   write answering to `roles.assign` rather than `users.update`. */
const assignment = useForm<{ roles: string[] }>({
    roles: [...(props.user?.roles ?? [])],
});

const roleOptions = computed<App.Data.OptionData[]>(() =>
    props.roles.map((role) => ({
        value: role.id,
        label: headline(role.name),
        hint: role.description ?? `Brings ${grantCount(role.name)}.`,
        image_url: null,
    })),
);

/* The form posts role names - what both requests read - while the combobox
   picks by id. This is the only place the two spellings meet. */
const pickedRoleIds = computed<number[]>({
    get: () =>
        props.roles
            .filter((role) => assignment.roles.includes(role.name))
            .map((role) => role.id),
    set: (ids) => {
        assignment.roles = props.roles
            .filter((role) => ids.includes(role.id))
            .map((role) => role.name);
    },
});

function grantCount(name: string): string {
    const count = props.role_grants[name]?.length ?? 0;

    return `${count} ${count === 1 ? 'permission' : 'permissions'}`;
}

// =========================================================================
// Permissions granted straight to the account
// =========================================================================

const grants = useForm<{ permissions: string[] }>({
    permissions: [...(props.user?.permissions ?? [])],
});

/* On an existing account this is the server's answer about the roles actually
   saved - not the picker above, which is a separate write, so a role ticked and
   unsaved grants nothing yet. Only while creating is it derived locally. */
const inherited = computed<Record<string, string[]>>(() => {
    if (props.user !== null) {
        return props.inherited;
    }

    const held: Record<string, string[]> = {};

    for (const role of assignment.roles) {
        for (const permission of props.role_grants[role] ?? []) {
            held[permission] = [...(held[permission] ?? []), role];
        }
    }

    return held;
});

function via(permission: string): string[] {
    return inherited.value[permission] ?? [];
}

/*
  A permission a role carries is ticked and locked rather than offered as a
  direct grant: pinning it as well would leave a second grant behind that
  survives the role being taken away. The one exception is a permission held
  both ways, which stays editable or it could never be undone - and
  `pinnedOnLoad` is read once at load because that question is about the server,
  not about the box somebody just unticked.
*/
function isInherited(permission: string): boolean {
    return via(permission).length > 0;
}

const pinnedOnLoad = new Set(props.user?.permissions ?? []);

function isDoubleHeld(permission: string): boolean {
    return isInherited(permission) && pinnedOnLoad.has(permission);
}

function isEditable(permission: App.Data.PermissionOptionData): boolean {
    return (
        props.can.permissions &&
        permission.grantable &&
        (!isInherited(permission.value) || isDoubleHeld(permission.value))
    );
}

function isTicked(permission: string): boolean {
    if (isInherited(permission) && !isDoubleHeld(permission)) {
        return true;
    }

    return grants.permissions.includes(permission);
}

function togglePermission(permission: string, checked: boolean) {
    grants.permissions = checked
        ? [...new Set([...grants.permissions, permission])]
        : grants.permissions.filter((value) => value !== permission);
}

function toggleGroup(group: App.Data.PermissionGroupData, checked: boolean) {
    const editable = group.permissions
        .filter(isEditable)
        .map((permission) => permission.value);

    grants.permissions = checked
        ? [...new Set([...grants.permissions, ...editable])]
        : grants.permissions.filter((value) => !editable.includes(value));
}

/* Only editable boxes count: a group whose rest comes from a role, or sits
   above the viewer's own ceiling, would never read as fully ticked. */
function groupState(
    group: App.Data.PermissionGroupData,
): boolean | 'indeterminate' {
    const editable = group.permissions.filter(isEditable);
    const held = editable.filter((permission) =>
        grants.permissions.includes(permission.value),
    );

    if (held.length === 0) {
        return false;
    }

    return held.length === editable.length ? true : 'indeterminate';
}

const inheritedCount = computed(() => Object.keys(inherited.value).length);

/* Saved roles once the account exists - what `inherited` answers about, and
   deliberately not the picker above while an edit is unsaved. */
const effectiveRoles = computed(() => props.user?.roles ?? assignment.roles);

/* A direct grant the selected roles already cover. The server refuses the
   overlap, so it is said here first. */
const redundant = computed(() =>
    props.user !== null
        ? []
        : grants.permissions.filter((permission) => isInherited(permission)),
);

// =========================================================================
// Saving
// =========================================================================

/* Creating posts the whole account at once; editing splits it into three
   writes, because each half answers to a different permission. */
function saveDetails() {
    if (props.user === null) {
        details
            .transform((data) => ({
                ...data,
                roles: assignment.roles,
                permissions: grants.permissions,
            }))
            .post(store().url);

        return;
    }

    details
        .transform(({ name, email }) => ({ name, email }))
        .patch(update(props.user.id).url);
}

function saveRoles() {
    if (props.user === null) {
        saveDetails();

        return;
    }

    assignment.patch(updateRoles(props.user.id).url, { preserveScroll: true });
}

function saveGrants() {
    if (props.user === null) {
        saveDetails();

        return;
    }

    grants.patch(updatePermissions(props.user.id).url, {
        preserveScroll: true,
    });
}

/* While creating there is one submit, so errors about roles and grants come
   back on `details`, which declares neither field - hence the widening cast. */
const detailsErrors = computed(
    () => details.errors as Record<string, string | undefined>,
);

const rolesError = computed(() =>
    props.user === null ? detailsErrors.value.roles : assignment.errors.roles,
);

const permissionsError = computed(() =>
    props.user === null
        ? detailsErrors.value.permissions
        : grants.errors.permissions,
);

const settingPassword = ref<PasswordSubject | null>(null);

/* The people list is a panel on the Roles screen, not a page of its own. */
defineOptions({
    layout: (page: { user: App.Data.UserFormData | null }) => ({
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Roles', href: rolesIndex().url },
            { title: page.user === null ? 'New user' : 'Edit user' },
        ],
    }),
});
</script>

<template>
    <Head :title="heading" />

    <div class="flex flex-col gap-5">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl leading-tight">{{ heading }}</h1>
                <Badge
                    v-if="props.user?.is_self"
                    variant="secondary"
                    data-test="is-self"
                >
                    You
                </Badge>
            </div>

            <p class="mt-2 text-sm text-muted-foreground">
                {{
                    props.user
                        ? 'The account itself: who it belongs to, the roles it holds, and anything pinned to the person rather than the job.'
                        : 'A name, an address to sign in with, and a password to start on. Put them in a role now or nothing they can see will work.'
                }}
            </p>
        </div>

        <form id="user-details" @submit.prevent="saveDetails">
            <Card as="section" class="gap-0 p-0">
                <div class="border-b border-divider px-5 py-3.5">
                    <h2
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        Account details
                    </h2>
                </div>

                <div class="p-5">
                    <div
                        class="flex flex-col gap-4 @2xl/page:grid @2xl/page:grid-cols-2 @2xl/page:gap-x-5.5 @2xl/page:gap-y-4.5"
                    >
                        <div>
                            <Label for="name">
                                Full name <span class="text-primary">*</span>
                            </Label>
                            <Input
                                id="name"
                                v-model="details.name"
                                class="mt-2.25"
                                placeholder="e.g. Achieng Odhiambo"
                                autocomplete="name"
                                data-test="field-name"
                            />
                            <InputError :message="details.errors.name" />
                        </div>

                        <div>
                            <Label for="email">
                                Email <span class="text-primary">*</span>
                            </Label>
                            <Input
                                id="email"
                                v-model="details.email"
                                type="email"
                                class="mt-2.25"
                                placeholder="e.g. achieng@sheffieldafrica.com"
                                autocomplete="email"
                                data-test="field-email"
                            />
                            <InputError :message="details.errors.email" />
                            <p
                                v-if="
                                    props.user &&
                                    details.email !== props.user.email
                                "
                                class="mt-1.5 text-xs text-muted-foreground"
                                data-test="email-reverify"
                            >
                                Changing this sends the account back to
                                unverified until the new address is confirmed.
                            </p>
                        </div>

                        <template v-if="props.user === null">
                            <div>
                                <Label for="password">
                                    Password <span class="text-primary">*</span>
                                </Label>
                                <div class="mt-2.25">
                                    <PasswordInput
                                        id="password"
                                        v-model="details.password"
                                        autocomplete="new-password"
                                        data-test="field-password"
                                    />
                                </div>
                                <InputError
                                    :message="details.errors.password"
                                />
                            </div>

                            <div>
                                <Label for="password_confirmation">
                                    Confirm password
                                    <span class="text-primary">*</span>
                                </Label>
                                <div class="mt-2.25">
                                    <PasswordInput
                                        id="password_confirmation"
                                        v-model="details.password_confirmation"
                                        autocomplete="new-password"
                                        data-test="field-password-confirmation"
                                    />
                                </div>
                                <InputError
                                    :message="
                                        details.errors.password_confirmation
                                    "
                                />
                            </div>
                        </template>
                    </div>
                </div>
            </Card>

            <div
                v-if="props.user"
                class="mt-5 flex items-center justify-end gap-3"
            >
                <Button as-child variant="outline">
                    <Link :href="rolesIndex().url">Cancel</Link>
                </Button>
                <Button
                    type="submit"
                    :disabled="!canSaveDetails"
                    data-test="save-user"
                >
                    Save changes
                </Button>
            </div>
        </form>

        <!-- ===================== Roles ===================== -->
        <form @submit.prevent="saveRoles">
            <Card as="section" class="gap-0 p-0">
                <div class="border-b border-divider px-5 py-3.5">
                    <h2
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        Roles
                    </h2>
                </div>

                <div class="p-5">
                    <p
                        v-if="!props.can.assign_roles"
                        class="rounded-lg bg-muted/50 p-4 text-sm text-muted-foreground"
                        data-test="cannot-assign"
                    >
                        {{
                            props.user === null
                                ? 'You can create the account but not staff it. Ask somebody who assigns roles to put them in one, or they will sign in to nothing.'
                                : props.user.is_self
                                  ? 'Your own roles are shown for reference. Nobody widens their own reach here — an account that can put itself in a role is not held by one.'
                                  : 'Shown for reference. Moving somebody between roles needs the roles.assign right.'
                        }}
                    </p>

                    <template v-if="props.can.assign_roles">
                        <Label for="roles" class="sr-only">Roles</Label>
                        <OptionMultiCombobox
                            id="roles"
                            v-model="pickedRoleIds"
                            :options="roleOptions"
                            placeholder="Choose the roles this account holds"
                            search-placeholder="Role name or what it is for"
                            empty-text="No role matches that."
                            data-test="field-roles"
                        />
                        <InputError
                            v-if="props.user === null"
                            :message="rolesError"
                        />
                    </template>

                    <div
                        v-else
                        class="mt-3 flex flex-wrap items-center gap-1.5"
                    >
                        <Badge
                            v-for="role in assignment.roles"
                            :key="role"
                            variant="secondary"
                            class="capitalize"
                            :data-test="`role-${role}`"
                        >
                            {{ headline(role) }}
                        </Badge>
                        <span
                            v-if="assignment.roles.length === 0"
                            class="text-xs text-muted-foreground"
                            data-test="holds-no-role"
                        >
                            No role at all.
                        </span>
                    </div>
                </div>

                <div
                    v-if="props.user && props.can.assign_roles"
                    class="flex flex-wrap items-center justify-end gap-3 border-t border-border px-5 py-4"
                >
                    <p
                        v-if="assignment.isDirty"
                        class="mr-auto text-xs text-muted-foreground"
                        data-test="roles-unsaved"
                    >
                        Save these before the permissions below can show what
                        they bring.
                    </p>
                    <InputError class="mr-auto" :message="rolesError" />
                    <Button
                        type="submit"
                        :disabled="assignment.processing"
                        data-test="save-roles"
                    >
                        Save roles
                    </Button>
                </div>
            </Card>
        </form>

        <!-- ===================== Permissions ===================== -->
        <form @submit.prevent="saveGrants">
            <Card as="section" class="gap-0 p-0">
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-3.5"
                >
                    <h2
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        Permissions
                    </h2>
                    <span class="text-xs text-faint tabular-nums">
                        <span data-test="direct-count">
                            {{ grants.permissions.length }} direct
                        </span>
                        <span aria-hidden="true"> &bull; </span>
                        <span data-test="inherited-count">
                            {{ inheritedCount }} from roles
                        </span>
                    </span>
                </div>

                <div class="border-b border-divider px-5 py-3.5">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="text-xs text-muted-foreground">
                            {{ props.user ? 'Roles held:' : 'Roles selected:' }}
                        </span>
                        <Badge
                            v-for="role in effectiveRoles"
                            :key="role"
                            variant="secondary"
                            class="capitalize"
                        >
                            {{ headline(role) }}
                        </Badge>
                        <span
                            v-if="effectiveRoles.length === 0"
                            class="text-xs text-muted-foreground"
                            data-test="no-roles"
                        >
                            None. Everything below is pinned to the person.
                        </span>
                    </div>

                    <p class="mt-2.5 text-xs text-muted-foreground">
                        A ticked box with a role beside it comes from that role
                        and goes when the role does. A ticked box without one is
                        pinned to this account and stays whatever happens to
                        their roles &mdash; which is why the Permissions screen
                        names the people holding one.
                    </p>
                </div>

                <p
                    v-if="!props.can.permissions"
                    class="m-5 rounded-lg bg-muted/50 p-4 text-sm text-muted-foreground"
                    data-test="permissions-read-only"
                >
                    {{
                        props.user === null
                            ? 'Shown for reference. Pinning a capability straight to an account needs the users.permissions right — create the account and ask somebody who holds it.'
                            : props.user.is_self
                              ? 'Your own grants are shown for reference. Nobody edits their own permissions here — an account that can rewrite its own ceiling is not a ceiling.'
                              : 'Shown for reference. Granting a capability straight to somebody needs the users.permissions right, and only over an account your own reach already covers.'
                    }}
                </p>

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
                                :disabled="!props.can.permissions"
                                :aria-label="`Pin every ${group.group} permission to this account`"
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
                                    isInherited(permission.value)
                                        ? `Granted by ${via(permission.value).map(headline).join(', ')}.`
                                        : permission.grantable
                                          ? undefined
                                          : 'You do not hold this permission yourself.'
                                "
                            >
                                <Checkbox
                                    :model-value="isTicked(permission.value)"
                                    :disabled="!isEditable(permission)"
                                    :data-test="`permission-${permission.value}`"
                                    @update:model-value="
                                        togglePermission(
                                            permission.value,
                                            $event === true,
                                        )
                                    "
                                />
                                {{ permission.label }}

                                <Badge
                                    v-if="isInherited(permission.value)"
                                    variant="outline"
                                    class="capitalize"
                                    :data-test="`via-${permission.value}`"
                                >
                                    <Shield class="size-3" />
                                    {{
                                        via(permission.value)
                                            .map(headline)
                                            .join(', ')
                                    }}
                                </Badge>

                                <Badge
                                    v-if="isDoubleHeld(permission.value)"
                                    variant="destructive"
                                    :data-test="`double-${permission.value}`"
                                    title="Held by a role and pinned to the account. Clear the box and save to leave only the role behind it."
                                >
                                    <Pin class="size-3" />
                                    Also pinned
                                </Badge>
                            </label>
                        </div>
                    </div>
                </div>

                <div
                    v-if="props.can.permissions"
                    class="flex flex-wrap items-center justify-end gap-3 border-t border-border px-5 py-4"
                >
                    <p
                        v-if="redundant.length > 0"
                        class="mr-auto text-xs text-destructive"
                        data-test="redundant-warning"
                    >
                        A role selected above already grants
                        {{ redundant.join(', ') }}. Clear
                        {{ redundant.length === 1 ? 'it' : 'them' }} here, or
                        the account cannot be created.
                    </p>
                    <InputError class="mr-auto" :message="permissionsError" />

                    <Button
                        v-if="props.user"
                        type="submit"
                        :disabled="grants.processing"
                        data-test="save-permissions"
                    >
                        Save direct permissions
                    </Button>
                    <p
                        v-else
                        class="text-xs text-muted-foreground"
                        data-test="grants-saved-with-account"
                    >
                        Saved with the account.
                    </p>
                </div>
            </Card>
        </form>

        <Card v-if="props.user" as="section" class="gap-0 p-0">
            <div class="border-b border-divider px-5 py-3.5">
                <h2
                    class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                >
                    Password
                </h2>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-4 p-5">
                <p class="max-w-prose text-sm text-muted-foreground">
                    {{
                        props.can.password
                            ? 'Sets a new password on their behalf and signs the account out everywhere. Nothing is emailed — you hand it over yourself.'
                            : props.user.is_self
                              ? 'Your own password is changed from Settings, where the current one has to be typed first. That check is the point of it, so it is not skipped here.'
                              : 'Setting a password for this account needs the users.update right over an account your own reach covers.'
                    }}
                </p>

                <Button
                    v-if="props.can.password"
                    variant="outline"
                    data-test="open-set-password"
                    @click="
                        settingPassword = {
                            id: props.user.id,
                            name: props.user.name,
                        }
                    "
                >
                    <KeyRound />
                    Set password
                </Button>
            </div>
        </Card>

        <div
            v-if="props.user === null"
            class="flex items-center justify-end gap-3"
        >
            <Button as-child variant="outline">
                <Link :href="rolesIndex().url">Cancel</Link>
            </Button>
            <Button
                type="submit"
                form="user-details"
                :disabled="!canSaveDetails || redundant.length > 0"
                data-test="save-user"
            >
                Add user
            </Button>
        </div>
    </div>

    <SetPasswordDialog
        :subject="settingPassword"
        @close="settingPassword = null"
    />
</template>
