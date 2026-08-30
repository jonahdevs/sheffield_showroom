<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Check, Eye, EyeOff, Minus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import OptionMultiCombobox from '@/components/OptionMultiCombobox.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { index as rolesIndex } from '@/routes/admin/roles';
import { store, update } from '@/routes/admin/users';

const props = defineProps<{
    user: App.Data.UserFormData | null;
    roles: App.Data.RoleData[];
    matrix: App.Data.PermissionGroupData[];
    can_grant: boolean;
}>();

const form = useForm<{
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
    roles: string[];
    permissions: string[];
}>({
    name: props.user?.name ?? '',
    email: props.user?.email ?? '',
    password: '',
    password_confirmation: '',
    roles: [...(props.user?.roles ?? [])],
    permissions: [...(props.user?.permissions ?? [])],
});

const isEditing = computed(() => props.user !== null);

const heading = computed(() => props.user?.name ?? 'New user');

// -----------------------------------------------------------------------------
// Roles
// -----------------------------------------------------------------------------

/*
  The box chooses ids, because that is what every option list in this
  application is keyed on. What goes over the wire stays the role's name, which
  is what the server validates and what `syncRoles` takes - so the two shapes
  are kept in step here rather than a second name-keyed picker existing for
  this one screen.
*/
const chosenRoleIds = ref<number[]>([...(props.user?.role_ids ?? [])]);

const roleOptions = computed<App.Data.OptionData[]>(() =>
    props.roles.map((role) => ({
        value: role.id,
        label: role.label,
        hint: role.description,
        image_url: null,
    })),
);

watch(chosenRoleIds, (ids) => {
    form.roles = ids
        .map((id) => props.roles.find((role) => role.id === id)?.name)
        .filter((name): name is string => name !== undefined);
});

// -----------------------------------------------------------------------------
// Direct permissions
// -----------------------------------------------------------------------------

function togglePermission(permission: string, checked: boolean) {
    form.permissions = checked
        ? [...new Set([...form.permissions, permission])]
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
 * Only the grantable ones count towards the group box. A group where the rest
 * is out of reach would otherwise never read as fully ticked.
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

// -----------------------------------------------------------------------------
// The form itself
// -----------------------------------------------------------------------------

const showPassword = ref(false);
const showConfirmation = ref(false);

const canSubmit = computed(() => {
    if (
        form.processing ||
        form.name.trim() === '' ||
        form.email.trim() === ''
    ) {
        return false;
    }

    /* Only a new account has to carry one; on an existing one the field is a
       replacement, and blank means leave it alone. */
    return isEditing.value || form.password !== '';
});

function submit() {
    if (props.user) {
        form.patch(update(props.user.id).url);

        return;
    }

    form.post(store().url);
}

defineOptions({
    layout: (page: { user: App.Data.UserFormData | null }) => ({
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Roles', href: rolesIndex().url },
            { title: page.user ? 'Edit user' : 'New user' },
        ],
    }),
});
</script>

<!--
  The account, then the way into it, then what it may do - which is the order
  the sections are trusted in. The email sits in the first section rather than
  beside the password because it is who this person is on the system, and it is
  here rather than on their own profile screen for the same reason: an address
  somebody can rewrite themselves is not an identity anyone else can rely on.

  Roles first and permissions under them, because a role is the answer nearly
  every time. The matrix below is for the exception a role would be a bad name
  for - the one person who also needs to pull the catalogue.
-->
<template>
    <Head :title="heading" />

    <div class="flex flex-col gap-5">
        <div>
            <h1 class="text-2xl leading-tight">{{ heading }}</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                {{
                    isEditing
                        ? 'Their name, the address they sign in with, and what they may do here.'
                        : 'Open an account for somebody on the floor. They sign in with the email you set here.'
                }}
            </p>
        </div>

        <form class="flex flex-col gap-5" @submit.prevent="submit">
            <Card as="section" class="gap-0 p-0">
                <div class="border-b border-divider px-5 py-3.5">
                    <h2
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        Account
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
                                v-model="form.name"
                                class="mt-2.25"
                                placeholder="e.g. Achieng Odhiambo"
                                autocomplete="name"
                                data-test="field-name"
                            />
                            <InputError :message="form.errors.name" />
                        </div>

                        <div>
                            <Label for="email">
                                Email address
                                <span class="text-primary">*</span>
                            </Label>
                            <Input
                                id="email"
                                v-model="form.email"
                                type="email"
                                class="mt-2.25"
                                placeholder="e.g. achieng@example.com"
                                autocomplete="off"
                                data-test="field-email"
                            />
                            <InputError :message="form.errors.email" />
                        </div>
                    </div>
                </div>
            </Card>

            <Card as="section" class="gap-0 p-0">
                <div class="border-b border-divider px-5 py-3.5">
                    <h2
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        Password
                    </h2>
                </div>

                <div class="p-5">
                    <p
                        v-if="isEditing"
                        class="mb-4 text-sm text-muted-foreground"
                    >
                        Leave both boxes empty to keep the password they already
                        have.
                    </p>

                    <div
                        class="flex flex-col gap-4 @2xl/page:grid @2xl/page:grid-cols-2 @2xl/page:gap-x-5.5 @2xl/page:gap-y-4.5"
                    >
                        <div>
                            <Label for="password">
                                {{ isEditing ? 'New password' : 'Password' }}
                                <span v-if="!isEditing" class="text-primary">
                                    *
                                </span>
                            </Label>
                            <!-- The reveal is an addon rather than a button
                                 floated over the field: it belongs to the box,
                                 and the group is what says so. -->
                            <InputGroup class="mt-2.25">
                                <InputGroupInput
                                    id="password"
                                    v-model="form.password"
                                    :type="showPassword ? 'text' : 'password'"
                                    autocomplete="new-password"
                                    placeholder="At least eight characters"
                                    data-test="field-password"
                                />
                                <InputGroupAddon align="inline-end">
                                    <InputGroupButton
                                        type="button"
                                        size="icon-xs"
                                        :aria-label="
                                            showPassword
                                                ? 'Hide password'
                                                : 'Show password'
                                        "
                                        :tabindex="-1"
                                        data-test="toggle-password"
                                        @click="showPassword = !showPassword"
                                    >
                                        <EyeOff v-if="showPassword" />
                                        <Eye v-else />
                                    </InputGroupButton>
                                </InputGroupAddon>
                            </InputGroup>
                            <InputError :message="form.errors.password" />
                        </div>

                        <div>
                            <Label for="password_confirmation">
                                Confirm password
                                <span v-if="!isEditing" class="text-primary">
                                    *
                                </span>
                            </Label>
                            <InputGroup class="mt-2.25">
                                <InputGroupInput
                                    id="password_confirmation"
                                    v-model="form.password_confirmation"
                                    :type="
                                        showConfirmation ? 'text' : 'password'
                                    "
                                    autocomplete="new-password"
                                    placeholder="Type it again"
                                    data-test="field-password-confirmation"
                                />
                                <InputGroupAddon align="inline-end">
                                    <InputGroupButton
                                        type="button"
                                        size="icon-xs"
                                        :aria-label="
                                            showConfirmation
                                                ? 'Hide password'
                                                : 'Show password'
                                        "
                                        :tabindex="-1"
                                        data-test="toggle-password-confirmation"
                                        @click="
                                            showConfirmation = !showConfirmation
                                        "
                                    >
                                        <EyeOff v-if="showConfirmation" />
                                        <Eye v-else />
                                    </InputGroupButton>
                                </InputGroupAddon>
                            </InputGroup>
                            <InputError
                                :message="form.errors.password_confirmation"
                            />
                        </div>
                    </div>
                </div>
            </Card>

            <!-- Only for somebody who may hand these out, and never on their
                 own account: an account that can widen its own reach is a
                 ceiling that does not hold. -->
            <template v-if="props.can_grant">
                <Card as="section" class="gap-0 p-0" data-test="roles-section">
                    <div class="border-b border-divider px-5 py-3.5">
                        <h2
                            class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                        >
                            Roles
                        </h2>
                    </div>

                    <div class="p-5">
                        <Label for="roles">Roles held</Label>
                        <div class="mt-2.25">
                            <OptionMultiCombobox
                                id="roles"
                                v-model="chosenRoleIds"
                                :options="roleOptions"
                                placeholder="Choose any number"
                                search-placeholder="Type to search roles"
                                empty-text="No role by that name."
                                data-test="field-roles"
                            />
                        </div>
                        <p class="mt-2.5 text-xs text-faint">
                            An account with no role keeps its login and can do
                            nothing with it.
                        </p>
                        <InputError :message="form.errors.roles" />
                    </div>
                </Card>

                <Card
                    as="section"
                    class="gap-0 p-0"
                    data-test="permissions-section"
                >
                    <div
                        class="flex items-center justify-between border-b border-divider px-5 py-3.5"
                    >
                        <h2
                            class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                        >
                            Direct permissions
                        </h2>
                        <span
                            class="text-xs text-faint tabular-nums"
                            data-test="permission-count"
                        >
                            {{ form.permissions.length }} selected
                        </span>
                    </div>

                    <p
                        class="border-b border-divider px-5 py-3.5 text-xs text-muted-foreground"
                    >
                        Held by this account alone, on top of whatever its roles
                        already carry. Reach for a role first: a permission
                        given here is one nobody will think to look for later.
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
                                    :aria-label="`Select every ${group.group} permission`"
                                    @update:model-value="
                                        toggleGroup(group, $event === true)
                                    "
                                >
                                    <Minus
                                        v-if="
                                            groupState(group) ===
                                            'indeterminate'
                                        "
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
                                        :disabled="!permission.grantable"
                                        :data-test="`permission-${permission.value}`"
                                        @update:model-value="
                                            togglePermission(
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

                    <div v-if="form.errors.permissions" class="px-5 pb-5">
                        <InputError :message="form.errors.permissions" />
                    </div>
                </Card>
            </template>

            <div class="flex items-center justify-end gap-3">
                <Button as-child variant="quiet">
                    <Link :href="rolesIndex().url">Cancel</Link>
                </Button>
                <Button
                    type="submit"
                    :disabled="!canSubmit"
                    data-test="save-user"
                >
                    {{ isEditing ? 'Save changes' : 'Add user' }}
                </Button>
            </div>
        </form>
    </div>
</template>
