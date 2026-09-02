<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Search, TicketX } from '@lucide/vue';
import { onScopeDispose, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { store } from '@/routes/admin/rewards/redeem';

export type RedeemLookup = {
    code: string;
    searched: boolean;
    reward: App.Data.ShuffleRewardData | null;
    can_redeem: boolean;
};

const props = defineProps<{ open: boolean; lookup: RedeemLookup }>();

const emit = defineEmits<{ (event: 'update:open', open: boolean): void }>();

/*
 * The lookup is a filter, not a form post, so the code lands in the query string
 * beside the list's own filters: the dialog can be reloaded, bookmarked or read
 * back over the phone. Only `redeem` is asked for, so typing a code does not
 * redraw the table behind it.
 */
const code = ref(props.lookup.code);

const searching = ref(false);

let timer: ReturnType<typeof setTimeout> | undefined;

function look(): void {
    router.reload({
        only: ['redeem'],
        data: { redeem: code.value },
        replace: true,
        onStart: () => (searching.value = true),
        onFinish: () => (searching.value = false),
    });
}

watch(code, (value) => {
    clearTimeout(timer);

    if (value.trim() === props.lookup.code) {
        return;
    }

    timer = setTimeout(look, 300);
});

onScopeDispose(() => clearTimeout(timer));

const redemption = useForm({ code: '', notes: '' });

/* Cleared on open rather than on close, or notes typed against one code show up
   prefilled against the next. */
watch(
    () => props.open,
    (open) => {
        if (open) {
            code.value = props.lookup.code;
            redemption.reset();
            redemption.clearErrors();
        }
    },
);

function redeem(): void {
    redemption.code = props.lookup.reward?.code ?? code.value;

    redemption.post(store().url, { preserveScroll: true });
}
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Redeem a reward</DialogTitle>
                <DialogDescription>
                    Type the code the customer quotes. Case and spacing do not
                    matter.
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-col gap-4">
                <div>
                    <Label for="redeem-code" class="sr-only">Reward code</Label>
                    <InputGroup>
                        <InputGroupInput
                            id="redeem-code"
                            v-model="code"
                            placeholder="SHF-8KD2QP"
                            autocomplete="off"
                            spellcheck="false"
                            class="font-mono tracking-widest uppercase"
                            data-test="redeem-code"
                        />
                        <InputGroupAddon>
                            <Search />
                        </InputGroupAddon>
                    </InputGroup>
                </div>

                <div
                    v-if="
                        props.lookup.searched &&
                        props.lookup.reward === null &&
                        !searching
                    "
                    class="flex items-center gap-3 rounded-lg bg-muted/60 p-3.5"
                    data-test="not-found"
                >
                    <TicketX class="size-5 shrink-0 text-muted-foreground" />
                    <p class="text-sm text-muted-foreground">
                        No reward has that code. Check it and try again.
                    </p>
                </div>

                <div
                    v-else-if="props.lookup.reward"
                    class="rounded-lg border border-border"
                    data-test="reward-found"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-3 border-b border-divider px-4 py-3"
                    >
                        <h3
                            class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                        >
                            {{ props.lookup.reward.type_label }}
                        </h3>
                        <Badge
                            :variant="
                                props.lookup.reward.is_redeemable
                                    ? 'secondary'
                                    : 'destructive'
                            "
                            data-test="reward-status"
                        >
                            {{ props.lookup.reward.status_label }}
                        </Badge>
                    </div>

                    <div class="flex flex-col gap-4 p-4">
                        <div>
                            <span class="text-lg font-bold">
                                {{ props.lookup.reward.name }}
                            </span>
                            <span
                                v-if="props.lookup.reward.value"
                                class="ml-2 text-lg font-bold text-primary"
                            >
                                {{ props.lookup.reward.value }}
                            </span>
                        </div>

                        <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Won by
                                </dt>
                                <dd
                                    class="font-bold"
                                    data-test="reward-customer"
                                >
                                    {{
                                        props.lookup.reward.customer_name ??
                                        'Unknown'
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Won on
                                </dt>
                                <dd>{{ props.lookup.reward.won_on }}</dd>
                            </div>
                            <div v-if="props.lookup.reward.expires_on">
                                <dt class="text-xs text-muted-foreground">
                                    Expires
                                </dt>
                                <dd>{{ props.lookup.reward.expires_on }}</dd>
                            </div>
                            <div v-if="props.lookup.reward.redeemed_on">
                                <dt class="text-xs text-muted-foreground">
                                    Redeemed
                                </dt>
                                <dd data-test="already-redeemed">
                                    {{ props.lookup.reward.redeemed_on }}
                                    <template
                                        v-if="props.lookup.reward.redeemed_by"
                                    >
                                        by
                                        {{ props.lookup.reward.redeemed_by }}
                                    </template>
                                </dd>
                            </div>
                        </dl>

                        <p
                            v-if="props.lookup.reward.terms"
                            class="rounded-lg bg-muted/60 p-3 text-xs text-muted-foreground"
                        >
                            {{ props.lookup.reward.terms }}
                        </p>
                    </div>

                    <form
                        v-if="props.lookup.can_redeem"
                        class="border-t border-border p-4"
                        @submit.prevent="redeem"
                    >
                        <Label for="redeem-notes">Notes</Label>
                        <Textarea
                            id="redeem-notes"
                            v-model="redemption.notes"
                            class="mt-2.25"
                            rows="2"
                            placeholder="Anything worth recording about how it was used."
                            data-test="redeem-notes"
                        />
                        <InputError
                            class="mt-2"
                            :message="redemption.errors.code"
                        />

                        <div class="mt-4 flex justify-end">
                            <Button
                                type="submit"
                                :disabled="redemption.processing"
                                data-test="confirm-redeem"
                            >
                                <CheckCircle2 />
                                Mark as redeemed
                            </Button>
                        </div>
                    </form>

                    <p
                        v-else
                        class="border-t border-border p-4 text-sm text-muted-foreground"
                        data-test="cannot-redeem"
                    >
                        {{
                            props.lookup.reward.is_redeemable
                                ? 'Handing a reward over needs the rewards.redeem right.'
                                : 'This reward cannot be handed over — it has already been used, or its date has passed.'
                        }}
                    </p>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
