<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Ban,
    CircleCheck,
    PackageOpen,
    Pause,
    Play,
    Plus,
    Rocket,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import type { Component } from 'vue';
import AlertError from '@/components/AlertError.vue';
import DatePicker from '@/components/DatePicker.vue';
import InputError from '@/components/InputError.vue';
import OptionMultiCombobox from '@/components/OptionMultiCombobox.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { confirmDelete } from '@/lib/confirm';
import { dashboard } from '@/routes';
import {
    destroy,
    index,
    publish,
    store,
    transition,
    update,
} from '@/routes/admin/rewards';

const props = defineProps<{
    campaign: App.Data.RewardCampaignData | null;
    /* Includes rewards retired since a draft picked them up: dropping them
       would blank the row when the draft is reopened. */
    catalogue: CatalogueReward[];
    /* The live floor only. A product withdrawn since it was paired keeps its
       name through `pairedProducts` below, not by being put back on offer. */
    products: App.Data.OptionData[];
    can: { update: boolean; publish: boolean; delete: boolean };
}>();

const heading = computed(() => props.campaign?.name ?? 'New campaign');

/** Reading needs only `rewards.view`, so this screen is also the one to look. */
const readOnly = computed(() => !props.can.update);

/**
 * Once `publish` writes the pool the quantities are live inventory, so the
 * reward rows stop being inputs. The server holds the same line:
 * `RewardCampaignRequest::editsRewards` drops anything arriving under
 * `rewards` at a published campaign, so a tab left open on the draft cannot
 * rewrite the odds by being saved an hour later. Everything else stays editable.
 */
const rewardsLocked = computed(() => props.campaign?.is_published ?? false);

const isClosed = computed(
    () =>
        props.campaign?.status === 'completed' ||
        props.campaign?.status === 'cancelled',
);

// =========================================================================
// The form
// =========================================================================

/** Nothing here describes the reward - the catalogue does, so nothing drifts. */
type RewardRow = {
    reward_id: string;
    quantity: string;
    validity_days: string;
    qualifying_product_ids: number[];
};

type CatalogueReward = {
    id: number;
    name: string;
    description: string | null;
    type: string;
    type_label: string;
    product_id: number | null;
    product_name: string | null;
    value_label: string | null;
    terms: string | null;
    default_validity_days: number | null;
    is_active: boolean;
};

type CampaignForm = {
    name: string;
    description: string;
    starts_at: string;
    ends_at: string;
    max_shuffles_per_customer: string;
    minimum_purchase_amount: string;
    rewards: RewardRow[];
};

/** What the request will take at most, said here so the button can stop first. */
const MAX_REWARDS = 20;

/**
 * A stored `Y-m-d H:i:s` as the date picker wants it: the day alone. String
 * surgery rather than a `Date` round trip, which would drag the value through
 * the browser's timezone. `RewardCampaignRequest` puts the end date back to
 * the close of its day, so "ends on the 28th" still means the 28th is in.
 */
function toDateInput(value: string | null): string {
    return value === null ? '' : value.slice(0, 10);
}

function blankReward(): RewardRow {
    return {
        reward_id: '',
        quantity: '1',
        validity_days: '',
        qualifying_product_ids: [],
    };
}

function chosen(row: RewardRow): CatalogueReward | null {
    const id = Number(row.reward_id);

    return props.catalogue.find((reward) => reward.id === id) ?? null;
}

/**
 * `OptionMultiCombobox`'s `selected`: names it can draw without adding them to
 * the list a row may pick from. Without it a product withdrawn from the floor
 * since it was paired would render as a bare id.
 */
const pairedProducts = computed<App.Data.OptionData[]>(() =>
    (props.campaign?.rewards ?? []).flatMap((reward) =>
        reward.qualifying_products.map((product) => ({
            value: product.id,
            label: product.name,
            hint: null,
            image_url: null,
        })),
    ),
);

const productNames = computed(() => {
    const names = new Map<number, string>();

    for (const option of [...props.products, ...pairedProducts.value]) {
        names.set(option.value, option.label);
    }

    return names;
});

function pairedNames(ids: number[]): string {
    return ids.map((id) => productNames.value.get(id) ?? `#${id}`).join(', ');
}

function namesOf(products: { id: number; name: string }[]): string {
    return products.map((product) => product.name).join(', ');
}

/**
 * `loaded` is the quantity: on a draft there is no pool yet, so
 * `CampaignRewardData` reports the definition's own number there.
 */
function savedRewards(campaign: App.Data.RewardCampaignData): RewardRow[] {
    return campaign.rewards.map((reward) => {
        return {
            reward_id: String(reward.reward_id),
            quantity: String(reward.loaded),
            validity_days:
                reward.validity_days === null
                    ? ''
                    : String(reward.validity_days),
            /* The controller reads the pairing with trashed rows included, so
               a product withdrawn since it was paired still arrives here and
               reopening a draft and saving it cannot silently unpair it. */
            qualifying_product_ids: reward.qualifying_products.map(
                (product) => product.id,
            ),
        };
    });
}

const form = useForm<CampaignForm>({
    name: props.campaign?.name ?? '',
    description: props.campaign?.description ?? '',
    starts_at: toDateInput(props.campaign?.starts_at ?? null),
    ends_at: toDateInput(props.campaign?.ends_at ?? null),
    max_shuffles_per_customer: String(
        props.campaign?.max_shuffles_per_customer ?? 1,
    ),
    minimum_purchase_amount: props.campaign?.minimum_purchase_amount ?? '',
    rewards:
        props.campaign === null
            ? [blankReward()]
            : savedRewards(props.campaign),
});

const canAddReward = computed(() => form.rewards.length < MAX_REWARDS);

function addReward() {
    if (!canAddReward.value) {
        return;
    }

    form.rewards.push(blankReward());
}

function dropReward(index: number) {
    form.rewards.splice(index, 1);
}

/** Row-keyed errors - `rewards.2.quantity` - which the typed bag cannot name. */
const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

function rewardError(index: number, field: string): string | undefined {
    return errors.value[`rewards.${index}.${field}`];
}

function blankToNull(value: string): string | null {
    const trimmed = value.trim();

    return trimmed === '' ? null : trimmed;
}

/** A cleared number box is null rather than zero, so validation says "required". */
function numberOrNull(value: string): number | null {
    const trimmed = value.trim();

    return trimmed === '' ? null : Number(trimmed);
}

/**
 * Past publication `rewards` is left out of the payload rather than sent and
 * dropped. The server drops it either way, so this decides nothing - it keeps
 * the request honest about what the screen is asking for.
 */
function submit() {
    form.transform((data) => {
        const campaign = {
            name: data.name,
            description: blankToNull(data.description),
            starts_at: blankToNull(data.starts_at),
            ends_at: blankToNull(data.ends_at),
            max_shuffles_per_customer: numberOrNull(
                data.max_shuffles_per_customer,
            ),
            minimum_purchase_amount: blankToNull(data.minimum_purchase_amount),
        };

        if (rewardsLocked.value) {
            return campaign;
        }

        return {
            ...campaign,
            rewards: data.rewards.map((reward) => ({
                reward_id: numberOrNull(reward.reward_id),
                quantity: numberOrNull(reward.quantity),
                validity_days: numberOrNull(reward.validity_days),
                qualifying_product_ids: reward.qualifying_product_ids,
            })),
        };
    });

    if (props.campaign === null) {
        form.post(store().url);

        return;
    }

    form.patch(update(props.campaign.id).url);
}

// -------------------------------------------------------------------------
// Publishing, and the states after it
// -------------------------------------------------------------------------

/**
 * The buttons below are `router` posts rather than form submissions, so they
 * have no error bag; `CampaignService`'s refusal arrives keyed `campaign`.
 */
const stateError = ref<string | null>(null);

type StateMove = {
    to: string;
    label: string;
    icon: Component;
    hint: string;
    /** Whether it ends the campaign for good. */
    final: boolean;
};

/**
 * Kept in step with `CampaignService` by hand - the transitions live in the
 * service rather than on the enum, and a move it would refuse must not appear.
 */
const stateMoves = computed<StateMove[]>(() => {
    const campaign = props.campaign;

    if (
        campaign === null ||
        !campaign.is_published ||
        isClosed.value ||
        !props.can.update
    ) {
        return [];
    }

    const moves: StateMove[] = [];

    if (campaign.status === 'scheduled' || campaign.status === 'paused') {
        moves.push({
            to: 'active',
            label: 'Start',
            icon: Play,
            hint: 'Starts handing out turns now.',
            final: false,
        });
    }

    if (campaign.status === 'active' || campaign.status === 'scheduled') {
        moves.push({
            to: 'paused',
            label: 'Pause',
            icon: Pause,
            hint: 'Stops it without ending it. The pool and the turns already handed out are untouched.',
            final: false,
        });
    }

    moves.push(
        {
            to: 'completed',
            label: 'Complete',
            icon: CircleCheck,
            hint: 'Ends the campaign. Every result and redemption behind it is kept.',
            final: true,
        },
        {
            to: 'cancelled',
            label: 'Cancel',
            icon: Ban,
            hint: 'Calls the campaign off. Rewards already won stay won.',
            final: true,
        },
    );

    return moves;
});

function postState(url: string, data: Record<string, string> = {}) {
    stateError.value = null;

    router.post(url, data, {
        preserveScroll: true,
        onError: (bag: Record<string, string>) => {
            stateError.value = bag.campaign ?? null;
        },
    });
}

/* `confirmDelete` is the only confirm dialog and only its text is ours, so its
   destructive styling stands while it asks about publishing. */
async function publishCampaign() {
    const campaign = props.campaign;

    if (campaign === null) {
        return;
    }

    const agreed = await confirmDelete(
        'Publishing writes the reward pool and opens the doors. The quantities below become inventory from that moment and can never be changed - this cannot be undone.',
        'Yes, publish it!',
    );

    if (!agreed) {
        return;
    }

    postState(publish(campaign.id).url);
}

async function moveTo(move: StateMove) {
    const campaign = props.campaign;

    if (campaign === null) {
        return;
    }

    /* Only the two that close it: starting and pausing each undo the other. */
    if (move.final) {
        const agreed = await confirmDelete(
            `${move.hint} The campaign cannot be started again afterwards.`,
            `Yes, ${move.label.toLowerCase()} it!`,
        );

        if (!agreed) {
            return;
        }
    }

    postState(transition(campaign.id).url, { to: move.to });
}

/* Only ever a draft: `RewardCampaignPolicy::delete` refuses a published
   campaign, so `can.delete` is false once there is history to lose. */
async function removeCampaign() {
    const campaign = props.campaign;

    if (campaign === null) {
        return;
    }

    if (!(await confirmDelete())) {
        return;
    }

    router.delete(destroy(campaign.id).url);
}

defineOptions({
    layout: (page: { campaign: App.Data.RewardCampaignData | null }) => ({
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Rewards', href: index().url },
            { title: page.campaign === null ? 'Create' : 'Edit' },
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
                    v-if="props.campaign"
                    variant="secondary"
                    data-test="campaign-status"
                >
                    {{ props.campaign.status_label }}
                </Badge>
                <Badge
                    v-if="props.campaign?.is_running"
                    variant="outline"
                    data-test="campaign-running"
                >
                    Handing out turns now
                </Badge>
            </div>

            <p class="mt-2 text-sm text-muted-foreground">
                {{
                    props.campaign === null
                        ? 'The dates it runs between, and what is in the drawer. Nothing is handed out until you publish it.'
                        : rewardsLocked
                          ? 'Published. The name, the dates and the ceiling are still yours to correct; the drawer is inventory now and is shown as it stands.'
                          : 'Still a draft, so everything here can be reshaped. Publish it when the drawer is right.'
                }}
            </p>
        </div>

        <AlertError
            v-if="stateError"
            :errors="[stateError]"
            title="That change was refused."
            data-test="state-error"
        />

        <form class="flex flex-col gap-5" @submit.prevent="submit">
            <Card as="section" class="gap-0 p-0">
                <div class="border-b border-divider px-5 py-3.5">
                    <h2
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        The campaign
                    </h2>
                </div>

                <div class="p-5">
                    <div
                        class="flex flex-col gap-4 @2xl/page:grid @2xl/page:grid-cols-2 @2xl/page:gap-x-5.5 @2xl/page:gap-y-4.5"
                    >
                        <div class="@2xl/page:col-span-2">
                            <Label for="name">
                                Name <span class="text-primary">*</span>
                            </Label>
                            <Input
                                id="name"
                                v-model="form.name"
                                class="mt-2.25"
                                :disabled="readOnly"
                                placeholder="e.g. Easter kitchen shuffle"
                                data-test="field-name"
                            />
                            <InputError :message="form.errors.name" />
                        </div>

                        <div class="@2xl/page:col-span-2">
                            <Label for="description">Description</Label>
                            <Textarea
                                id="description"
                                v-model="form.description"
                                class="mt-2.25"
                                :disabled="readOnly"
                                rows="2"
                                placeholder="What the promotion is, in a line the floor can repeat."
                                data-test="field-description"
                            />
                            <InputError :message="form.errors.description" />
                        </div>

                        <!-- Empty means "on publication" and "until stopped";
                             publishing reads the start to choose the status. -->
                        <div>
                            <Label for="starts_at">Starts</Label>
                            <div class="mt-2.25">
                                <DatePicker
                                    id="starts_at"
                                    v-model="form.starts_at"
                                    placeholder="On publication"
                                    :disabled="readOnly"
                                    data-test="field-starts-at"
                                />
                            </div>
                            <InputError :message="form.errors.starts_at" />
                            <p class="mt-1.5 text-xs text-muted-foreground">
                                Leave empty to run from the moment it is
                                published.
                            </p>
                        </div>

                        <div>
                            <Label for="ends_at">Ends</Label>
                            <div class="mt-2.25">
                                <DatePicker
                                    id="ends_at"
                                    v-model="form.ends_at"
                                    placeholder="No closing date"
                                    :disabled="readOnly"
                                    data-test="field-ends-at"
                                />
                            </div>
                            <InputError :message="form.errors.ends_at" />
                            <p class="mt-1.5 text-xs text-muted-foreground">
                                Leave empty to run until somebody stops it. The
                                day chosen is the last one it runs.
                            </p>
                        </div>

                        <div>
                            <Label for="max_shuffles_per_customer">
                                Turns per customer
                                <span class="text-primary">*</span>
                            </Label>
                            <Input
                                id="max_shuffles_per_customer"
                                v-model="form.max_shuffles_per_customer"
                                type="number"
                                min="1"
                                max="100"
                                class="mt-2.25"
                                :disabled="readOnly"
                                data-test="field-max-shuffles"
                            />
                            <InputError
                                :message="form.errors.max_shuffles_per_customer"
                            />
                            <p class="mt-1.5 text-xs text-muted-foreground">
                                The ceiling one customer can reach, however many
                                qualifying purchases they make.
                            </p>
                        </div>

                        <div>
                            <Label for="minimum_purchase_amount">
                                Minimum purchase
                            </Label>
                            <Input
                                id="minimum_purchase_amount"
                                v-model="form.minimum_purchase_amount"
                                type="number"
                                min="0"
                                step="0.01"
                                class="mt-2.25"
                                :disabled="readOnly"
                                placeholder="e.g. 50000"
                                data-test="field-minimum-purchase"
                            />
                            <InputError
                                :message="form.errors.minimum_purchase_amount"
                            />
                            <p class="mt-1.5 text-xs text-muted-foreground">
                                In shillings. Leave empty and any purchase earns
                                a turn.
                            </p>
                        </div>
                    </div>
                </div>
            </Card>

            <Card as="section" class="gap-0 p-0">
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-divider px-5 py-3.5"
                >
                    <h2
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        The drawer
                    </h2>

                    <span
                        v-if="!rewardsLocked"
                        class="text-xs text-faint tabular-nums"
                        data-test="reward-count"
                    >
                        {{ form.rewards.length }} of {{ MAX_REWARDS }}
                    </span>
                    <span
                        v-else
                        class="text-xs text-faint tabular-nums"
                        data-test="drawer-total"
                    >
                        {{ props.campaign?.loaded }} loaded
                        <span aria-hidden="true">&bull;</span>
                        {{ props.campaign?.available }} available
                        <span aria-hidden="true">&bull;</span>
                        {{ props.campaign?.claimed }} claimed
                        <span aria-hidden="true">&bull;</span>
                        {{ props.campaign?.void }} void
                    </span>
                </div>

                <!-- ===================== The drawer, published ===================== -->
                <div
                    v-if="rewardsLocked"
                    class="divide-y divide-border"
                    data-test="rewards-locked"
                >
                    <div
                        v-for="reward in props.campaign?.rewards ?? []"
                        :key="reward.id"
                        class="px-5 py-4"
                        :data-test="`reward-${reward.id}`"
                    >
                        <div class="flex flex-wrap items-center gap-2.5">
                            <p class="text-xs font-bold">{{ reward.name }}</p>
                            <Badge variant="secondary">
                                {{ reward.type_label }}
                            </Badge>
                            <Badge v-if="reward.value_label" variant="outline">
                                {{ reward.value_label }}
                            </Badge>
                            <Badge
                                v-if="reward.validity_days"
                                variant="outline"
                            >
                                Valid {{ reward.validity_days }}
                                {{
                                    reward.validity_days === 1 ? 'day' : 'days'
                                }}
                            </Badge>
                        </div>

                        <p
                            v-if="reward.description"
                            class="mt-1.5 text-xs text-muted-foreground"
                        >
                            {{ reward.description }}
                        </p>

                        <dl
                            class="mt-3 grid max-w-md grid-cols-4 gap-3 text-xs"
                        >
                            <div>
                                <dt class="text-faint">Loaded</dt>
                                <dd class="mt-0.5 font-bold tabular-nums">
                                    {{ reward.loaded }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-faint">Available</dt>
                                <dd class="mt-0.5 font-bold tabular-nums">
                                    {{ reward.available }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-faint">Claimed</dt>
                                <dd class="mt-0.5 font-bold tabular-nums">
                                    {{ reward.claimed }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-faint">Void</dt>
                                <dd class="mt-0.5 font-bold tabular-nums">
                                    {{ reward.void }}
                                </dd>
                            </div>
                        </dl>

                        <!-- Said even when empty: paired to nothing is a real
                             state, not a pairing nobody got round to. -->
                        <p
                            class="mt-3 text-xs text-muted-foreground"
                            :data-test="`reward-${reward.id}-paired`"
                        >
                            <template
                                v-if="reward.qualifying_products.length > 0"
                            >
                                Paired to
                                {{ namesOf(reward.qualifying_products) }} - only
                                a purchase of one of those can win it.
                            </template>
                            <template v-else>
                                Paired to nothing, so any purchase that earns a
                                turn can win it.
                            </template>
                        </p>

                        <p
                            v-if="reward.terms"
                            class="mt-3 text-xs text-muted-foreground"
                        >
                            {{ reward.terms }}
                        </p>
                    </div>
                </div>

                <!-- ===================== The drawer, as a draft ===================== -->
                <div v-else class="flex flex-col gap-4 p-5">
                    <div
                        v-for="(reward, position) in form.rewards"
                        :key="position"
                        class="rounded-xl border border-border p-4"
                        :data-test="`reward-row-${position}`"
                    >
                        <div class="flex items-center gap-3">
                            <span class="text-xs font-bold text-faint">
                                Reward {{ position + 1 }}
                            </span>

                            <Button
                                v-if="!readOnly"
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                class="ml-auto text-destructive hover:text-destructive"
                                :aria-label="`Remove reward ${position + 1}`"
                                :data-test="`drop-reward-${position}`"
                                @click="dropReward(position)"
                            >
                                <Trash2 />
                            </Button>
                        </div>

                        <div
                            class="mt-3 flex flex-col gap-4 @2xl/page:grid @2xl/page:grid-cols-4 @2xl/page:gap-x-5.5 @2xl/page:gap-y-4.5"
                        >
                            <div class="@2xl/page:col-span-2">
                                <Label :for="`reward-${position}-reward-id`">
                                    Reward <span class="text-primary">*</span>
                                </Label>
                                <NativeSelect
                                    :id="`reward-${position}-reward-id`"
                                    v-model="reward.reward_id"
                                    class="mt-2.25 w-full"
                                    :disabled="readOnly"
                                    :data-test="`reward-${position}-reward-id`"
                                >
                                    <NativeSelectOption value="">
                                        Choose a reward
                                    </NativeSelectOption>
                                    <NativeSelectOption
                                        v-for="option in props.catalogue"
                                        :key="option.id"
                                        :value="String(option.id)"
                                    >
                                        {{ option.name }} ({{
                                            option.type_label
                                        }}){{
                                            option.is_active ? '' : ' — retired'
                                        }}
                                    </NativeSelectOption>
                                </NativeSelect>
                                <InputError
                                    :message="
                                        rewardError(position, 'reward_id')
                                    "
                                />
                            </div>

                            <div class="@2xl/page:col-span-2">
                                <span class="text-sm text-muted-foreground">
                                    Reward details
                                </span>
                                <div
                                    v-if="chosen(reward)"
                                    class="mt-2.25 rounded-lg bg-muted/60 p-3 text-xs text-muted-foreground"
                                    :data-test="`reward-${position}-summary`"
                                >
                                    <p v-if="chosen(reward)?.value_label">
                                        Worth
                                        {{ chosen(reward)?.value_label }}
                                    </p>
                                    <p v-if="chosen(reward)?.product_name">
                                        Hands over
                                        {{ chosen(reward)?.product_name }}
                                    </p>
                                    <p v-if="chosen(reward)?.description">
                                        {{ chosen(reward)?.description }}
                                    </p>
                                    <p v-if="chosen(reward)?.terms">
                                        {{ chosen(reward)?.terms }}
                                    </p>
                                </div>
                                <p
                                    v-else
                                    class="mt-2.25 rounded-lg bg-muted/60 p-3 text-xs text-muted-foreground"
                                >
                                    Choose a reward to see what it is.
                                </p>
                            </div>

                            <div>
                                <Label :for="`reward-${position}-quantity`">
                                    Quantity
                                    <span class="text-primary">*</span>
                                </Label>
                                <Input
                                    :id="`reward-${position}-quantity`"
                                    v-model="reward.quantity"
                                    type="number"
                                    min="1"
                                    max="100000"
                                    class="mt-2.25"
                                    :disabled="readOnly"
                                    :data-test="`reward-${position}-quantity`"
                                />
                                <InputError
                                    :message="rewardError(position, 'quantity')"
                                />
                            </div>

                            <div>
                                <Label
                                    :for="`reward-${position}-validity-days`"
                                >
                                    Valid for
                                </Label>
                                <Input
                                    :id="`reward-${position}-validity-days`"
                                    v-model="reward.validity_days"
                                    type="number"
                                    min="1"
                                    max="3650"
                                    class="mt-2.25"
                                    :disabled="readOnly"
                                    placeholder="Days"
                                    :data-test="`reward-${position}-validity-days`"
                                />
                                <InputError
                                    :message="
                                        rewardError(position, 'validity_days')
                                    "
                                />
                                <!-- Stamped onto the win, so changing this
                                     never moves a deadline already handed out. -->
                                <p class="mt-1.5 text-xs text-muted-foreground">
                                    Counted from the moment it is won. Empty
                                    means it never expires.
                                </p>
                            </div>

                            <!-- The pairing belongs to the campaign, not the
                                 catalogue - `campaign_reward_product` is keyed
                                 on the attachment. Empty is a real default. -->
                            <div class="@2xl/page:col-span-2">
                                <Label :for="`reward-${position}-products`">
                                    Paired to
                                </Label>

                                <div class="mt-2.25">
                                    <OptionMultiCombobox
                                        v-if="!readOnly"
                                        :id="`reward-${position}-products`"
                                        v-model="reward.qualifying_product_ids"
                                        :options="props.products"
                                        :selected="pairedProducts"
                                        placeholder="Any purchase qualifies"
                                        search-placeholder="Product name or SKU"
                                        empty-text="No product matches that."
                                        :data-test="`reward-${position}-products`"
                                    />

                                    <!-- `OptionMultiCombobox` has no disabled
                                         state, so read-only gets names. -->
                                    <p
                                        v-else
                                        class="rounded-lg bg-muted/60 p-3 text-xs text-muted-foreground"
                                        :data-test="`reward-${position}-paired`"
                                    >
                                        {{
                                            reward.qualifying_product_ids
                                                .length > 0
                                                ? pairedNames(
                                                      reward.qualifying_product_ids,
                                                  )
                                                : 'Nothing - any purchase qualifies.'
                                        }}
                                    </p>
                                </div>

                                <InputError
                                    :message="
                                        rewardError(
                                            position,
                                            'qualifying_product_ids',
                                        )
                                    "
                                />

                                <p class="mt-1.5 text-xs text-muted-foreground">
                                    {{
                                        reward.qualifying_product_ids.length ===
                                        0
                                            ? 'Leave empty and any purchase that earns a turn can win this, which is what most rewards are.'
                                            : 'Only a purchase of one of these can win this. The campaign minimum above still decides who earns a turn at all.'
                                    }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- `border` by hand: `Empty` carries `border-dashed` and
                         no width, so its outline is invisible without one. -->
                    <Empty
                        v-if="form.rewards.length === 0"
                        class="border"
                        data-test="rewards-empty"
                    >
                        <EmptyHeader>
                            <EmptyMedia variant="icon">
                                <PackageOpen />
                            </EmptyMedia>
                            <EmptyTitle>The drawer is empty</EmptyTitle>
                            <EmptyDescription>
                                A campaign needs at least one reward before
                                anybody can win anything, and an empty drawer is
                                refused at publication.
                            </EmptyDescription>
                        </EmptyHeader>

                        <EmptyContent v-if="!readOnly">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                data-test="add-first-reward"
                                @click="addReward"
                            >
                                <Plus />
                                Add reward
                            </Button>
                        </EmptyContent>
                    </Empty>

                    <div class="flex flex-wrap items-center gap-3">
                        <Button
                            v-if="!readOnly && form.rewards.length > 0"
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="!canAddReward"
                            :title="
                                canAddReward
                                    ? undefined
                                    : `A campaign carries at most ${MAX_REWARDS} kinds of reward.`
                            "
                            data-test="add-reward"
                            @click="addReward"
                        >
                            <Plus />
                            Add reward
                        </Button>

                        <InputError
                            class="mt-0"
                            :message="errors.rewards"
                            data-test="rewards-error"
                        />
                    </div>
                </div>
            </Card>

            <div
                v-if="!readOnly"
                class="flex flex-wrap items-center justify-end gap-3"
            >
                <Button
                    v-if="props.can.delete"
                    type="button"
                    variant="ghost"
                    class="mr-auto text-destructive hover:text-destructive"
                    data-test="delete-campaign"
                    @click="removeCampaign"
                >
                    <Trash2 />
                    Delete draft
                </Button>

                <Button as-child variant="outline">
                    <Link :href="index().url">Cancel</Link>
                </Button>
                <Button
                    type="submit"
                    :disabled="form.processing || form.name.trim() === ''"
                    data-test="save-campaign"
                >
                    {{ props.campaign ? 'Save changes' : 'Create campaign' }}
                </Button>
            </div>

            <div v-else class="flex items-center justify-end">
                <Button as-child variant="outline">
                    <Link :href="index().url">Close</Link>
                </Button>
            </div>
        </form>

        <!-- ===================== Publication ===================== -->
        <!-- Its own writes, outside the form, so Save can never open the doors. -->
        <Card
            v-if="
                props.campaign &&
                (props.can.publish || stateMoves.length > 0 || isClosed)
            "
            as="section"
            class="gap-0 p-0"
        >
            <div class="border-b border-divider px-5 py-3.5">
                <h2
                    class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                >
                    Publication
                </h2>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-4 p-5">
                <p class="max-w-prose text-sm text-muted-foreground">
                    {{
                        props.can.publish
                            ? 'Publishing writes one pool entry for every unit in the drawer and opens the doors. It cannot be undone, and the quantities stop being editable the moment it happens.'
                            : 'The pool has been written. What is left is moving the campaign through its life — only one campaign hands out turns at a time.'
                    }}
                </p>

                <div class="flex flex-wrap items-center gap-2.5">
                    <Button
                        v-if="props.can.publish"
                        type="button"
                        data-test="publish-campaign"
                        @click="publishCampaign"
                    >
                        <Rocket />
                        Publish
                    </Button>

                    <Button
                        v-for="move in stateMoves"
                        :key="move.to"
                        type="button"
                        variant="outline"
                        :title="move.hint"
                        :data-test="`transition-${move.to}`"
                        @click="moveTo(move)"
                    >
                        <component :is="move.icon" />
                        {{ move.label }}
                    </Button>
                </div>
            </div>

            <p
                v-if="isClosed"
                class="border-t border-border px-5 py-3.5 text-xs text-muted-foreground"
                data-test="campaign-closed"
            >
                This campaign is over. Its results, redemptions and reporting
                are kept, and nothing can move it back.
            </p>
        </Card>
    </div>
</template>
