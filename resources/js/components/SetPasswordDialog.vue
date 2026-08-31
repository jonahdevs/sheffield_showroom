<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import { watch } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { update as setPassword } from '@/routes/admin/users/password';

/**
 * The account being given a new password. Structural rather than one generated
 * type, so the list's row data and the edit page's own record both satisfy it
 * without either pretending to be the other.
 */
export interface PasswordSubject {
    id: number;
    name: string;
}

const props = defineProps<{ subject: PasswordSubject | null }>();

const emit = defineEmits<{ (event: 'close'): void }>();

const form = useForm({ password: '', password_confirmation: '' });

/*
 * Cleared on the way in rather than on the way out. A password left sitting in
 * a closed dialog's state would be handed to whoever the menu is opened on
 * next, and the field would look filled for an account it was never typed for.
 */
watch(
    () => props.subject,
    (subject) => {
        if (subject !== null) {
            form.reset();
            form.clearErrors();
        }
    },
);

function submit() {
    if (props.subject === null) {
        return;
    }

    form.put(setPassword(props.subject.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            emit('close');
        },
    });
}
</script>

<!--
  Setting somebody else's password, kept behind a dialog of its own rather than
  sitting on the edit form as one more field.

  Two reasons it is not a field. It cannot be undone by typing the old value
  back, so it should never be something a stray keystroke does on the way to
  saving a corrected surname. And it is the one write here with a consequence
  the person doing it has to be told about first: whoever is signed in as this
  account is signed out of it, on every device, the moment Save is pressed.
-->
<template>
    <Dialog
        :open="props.subject !== null"
        @update:open="!$event && emit('close')"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>
                    Set a password for {{ props.subject?.name }}
                </DialogTitle>
                <DialogDescription>
                    Nothing is emailed. Hand it over in person, and ask them to
                    change it from Settings once they are in.
                </DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="grid gap-1.5">
                    <Label for="new-password">New password</Label>
                    <PasswordInput
                        id="new-password"
                        v-model="form.password"
                        autocomplete="new-password"
                        data-test="new-password"
                    />
                    <InputError :message="form.errors.password" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="new-password-confirmation">
                        Confirm password
                    </Label>
                    <PasswordInput
                        id="new-password-confirmation"
                        v-model="form.password_confirmation"
                        autocomplete="new-password"
                        data-test="new-password-confirmation"
                    />
                    <InputError :message="form.errors.password_confirmation" />
                </div>

                <p
                    class="flex items-start gap-1.5 rounded-lg bg-muted/50 p-3 text-xs text-muted-foreground"
                    data-test="password-consequence"
                >
                    <TriangleAlert class="mt-px size-3.5 shrink-0" />
                    <span>
                        Every session this account has open is ended, and any
                        browser it asked to be remembered on will have to sign
                        in again. A password changed because somebody should no
                        longer be in the account is not changed until that
                        happens.
                    </span>
                </p>

                <DialogFooter>
                    <Button variant="quiet" @click="emit('close')">
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing || form.password === ''"
                        data-test="confirm-set-password"
                    >
                        Set password and sign them out
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
