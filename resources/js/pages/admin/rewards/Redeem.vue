<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Search, TicketX } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useFilters } from '@/composables/useFilters';
import { dashboard } from '@/routes';
import { index as rewardsIndex } from '@/routes/admin/rewards';
import { index as redeemIndex, store } from '@/routes/admin/rewards/redeem';

const props = defineProps<{
    code: string;
    searched: boolean;
    reward: App.Data.ShuffleRewardData | null;
    can: { redeem: boolean };
}>();

/*
 * The lookup is a filter, not a form post, so the code lands in the URL and the
 * screen can be reloaded, bookmarked or read back over the phone.
 */
const { filters, processing } = useFilters({
    url: redeemIndex().url,
    initial: { code: props.code },
    blank: { code: '' },
    only: ['code', 'searched', 'reward', 'can'],
});

const redemption = useForm({ code: props.code, notes: '' });

const found = computed(() => props.reward !== null);

function redeem() {
    redemption.code = props.reward?.code ?? props.code;

    redemption.post(store().url, { preserveScroll: true });
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Rewards', href: rewardsIndex().url },
            { title: 'Redeem' },
        ],
    },
});
</script>

<template>
    <Head title="Redeem a reward" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-5">
        <div>
            <h1 class="text-2xl leading-tight">Redeem a reward</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                Type the code the customer quotes. Case and spacing do not
                matter.
            </p>
        </div>

        <Card as="section" class="gap-0 p-0">
            <div class="p-5">
                <Label for="code" class="sr-only">Reward code</Label>
                <InputGroup>
                    <InputGroupInput
                        id="code"
                        v-model="filters.code"
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
        </Card>

        <Card
            v-if="props.searched && !found && !processing"
            as="section"
            class="flex-row items-center gap-3 p-5"
            data-test="not-found"
        >
            <TicketX class="size-5 shrink-0 text-muted-foreground" />
            <p class="text-sm text-muted-foreground">
                No reward has that code. Check it and try again.
            </p>
        </Card>

        <template v-if="props.reward">
            <Card as="section" class="gap-0 p-0" data-test="reward-found">
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-divider px-5 py-3.5"
                >
                    <h2
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        {{ props.reward.type_label }}
                    </h2>
                    <Badge
                        :variant="
                            props.reward.is_redeemable
                                ? 'secondary'
                                : 'destructive'
                        "
                        data-test="reward-status"
                    >
                        {{ props.reward.status_label }}
                    </Badge>
                </div>

                <div class="flex flex-col gap-4 p-5">
                    <div>
                        <span class="text-xl font-bold">
                            {{ props.reward.name }}
                        </span>
                        <span
                            v-if="props.reward.value"
                            class="ml-2 text-xl font-bold text-primary"
                        >
                            {{ props.reward.value }}
                        </span>
                    </div>

                    <dl
                        class="grid gap-x-6 gap-y-2 text-sm @lg/page:grid-cols-2"
                    >
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Won by
                            </dt>
                            <dd class="font-bold" data-test="reward-customer">
                                {{ props.reward.customer_name ?? 'Unknown' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Won on
                            </dt>
                            <dd>{{ props.reward.won_on }}</dd>
                        </div>
                        <div v-if="props.reward.expires_on">
                            <dt class="text-xs text-muted-foreground">
                                Expires
                            </dt>
                            <dd>{{ props.reward.expires_on }}</dd>
                        </div>
                        <div v-if="props.reward.redeemed_on">
                            <dt class="text-xs text-muted-foreground">
                                Redeemed
                            </dt>
                            <dd data-test="already-redeemed">
                                {{ props.reward.redeemed_on }}
                                <template v-if="props.reward.redeemed_by">
                                    by {{ props.reward.redeemed_by }}
                                </template>
                            </dd>
                        </div>
                    </dl>

                    <p
                        v-if="props.reward.terms"
                        class="rounded-lg bg-muted/60 p-3.5 text-xs text-muted-foreground"
                    >
                        {{ props.reward.terms }}
                    </p>
                </div>

                <form
                    v-if="props.can.redeem"
                    class="border-t border-border p-5"
                    @submit.prevent="redeem"
                >
                    <Label for="notes">Notes</Label>
                    <Textarea
                        id="notes"
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
                    class="border-t border-border p-5 text-sm text-muted-foreground"
                    data-test="cannot-redeem"
                >
                    {{
                        props.reward.is_redeemable
                            ? 'Handing a reward over needs the rewards.redeem right.'
                            : 'This reward cannot be handed over — it has already been used, or its date has passed.'
                    }}
                </p>
            </Card>
        </template>
    </div>
</template>
