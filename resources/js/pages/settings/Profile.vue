<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);

const { can } = usePermissions();

/*
 * Off unless a role hands it over. The address is where a sign-in is made and
 * where a reset would land, so moving it is normally an administrator's job on
 * the Users screen - a showroom that would rather let its staff fix their own
 * typo grants `profile.email.update` and this becomes a field again.
 */
const canUpdateEmail = computed(() => can('profile.email.update'));
</script>

<template>
    <Head title="Profile settings" />

    <h1 class="sr-only">Profile settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Profile"
            :description="
                canUpdateEmail
                    ? 'Update your name and email address'
                    : 'Update your name'
            "
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    placeholder="Full name"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <!--
              A field or a fact, by whether the account is trusted to move it.
              Disabled rather than hidden: knowing which address you sign in
              with is worth reading even when it is not yours to change, and a
              disabled input posts nothing, so the name never reaches the
              server at all.
            -->
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    :name="canUpdateEmail ? 'email' : undefined"
                    :default-value="user.email"
                    :disabled="!canUpdateEmail"
                    :required="canUpdateEmail"
                    autocomplete="username"
                    placeholder="Email address"
                    :data-test="
                        canUpdateEmail ? 'field-email' : 'email-read-only'
                    "
                />
                <InputError
                    v-if="canUpdateEmail"
                    class="mt-2"
                    :message="errors.email"
                />
                <p v-else class="text-sm text-muted-foreground">
                    Ask an administrator to change this. It is the address you
                    sign in with, so it is not yours to move.
                </p>
            </div>

            <div v-if="page.props.mustVerifyEmail && !user.email_verified_at">
                <p class="-mt-4 text-sm text-muted-foreground">
                    Your email address is unverified.
                    <Link
                        :href="send()"
                        as="button"
                        class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                    >
                        Click here to re-send the verification email.
                    </Link>
                </p>

                <div
                    v-if="page.props.status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-green-600"
                >
                    A new verification link has been sent to your email address.
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-profile-button"
                    >Save</Button
                >
            </div>
        </Form>
    </div>

    <DeleteUser />
</template>
