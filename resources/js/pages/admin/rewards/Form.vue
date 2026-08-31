<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Ban,
    CircleCheck,
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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
    reward_types: { value: string; label: string }[];
    value_units: { value: string; label: string }[];
    can: { update: boolean; publish: boolean; delete: boolean };
}>();

const heading = computed(() => props.campaign?.name ?? 'New campaign');

/**
 * Reading a campaign needs only `rewards.view`, so this screen is also the
 * one a manager opens to look. A box you can type into whose typing is thrown
 * away is worse than one you cannot, so when the edits have nowhere to go the
 * fields say so by not accepting any.
 */
const readOnly = computed(() => !props.can.update);

/**
 * Whether the drawer has been turned into inventory.
 *
 * This is the rule the whole screen is built around. A draft's quantities are
 * an administrator's to reshape; the moment `publish` writes the pool they
 * become a promise made to customers - twenty discounts means twenty - so the
 * reward rows stop being inputs and become a read of what is left. The server
 * holds the same line from the other side: `RewardCampaignRequest::editsRewards`
 * drops anything arriving under `rewards` at a published campaign rather than
 * refusing it, so a tab left open on the draft cannot quietly rewrite the odds
 * by being saved an hour later.
 *
 * Everything else stays editable, because a name, a description and an end
 * date are administration rather than odds.
 */
const rewardsLocked = computed(() => props.campaign?.is_published ?? false);

const isClosed = computed(
    () =>
        props.campaign?.status === 'completed' ||
        props.campaign?.status === 'cancelled',
);

// -----------------------------------------------------------------------------
// The form
// -----------------------------------------------------------------------------

type RewardRow = {
    name: string;
    description: string;
    type: string;
    quantity: string;
    value: string;
    value_unit: string;
    validity_days: string;
    terms: string;
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
 * A moment as `datetime-local` wants it.
 *
 * The column arrives as `Y-m-d H:i:s` and the control speaks `Y-m-dTH:i`, so
 * the seconds are cut and the space becomes a `T`. Deliberately string
 * surgery rather than a `Date` round trip: parsing to a `Date` and formatting
 * back would drag the value through the browser's timezone, and a campaign
 * that opens at nine in Nairobi would come back reading eleven.
 */
/**
 * A stored `Y-m-d H:i:s` as the date picker wants it: the day alone.
 *
 * A campaign runs over days rather than to a minute, and the clock half of
 * these columns was never something anybody set deliberately - it arrived
 * because the field used to be a `datetime-local`. Dropping it here is what
 * lets the calendar be a calendar; `RewardCampaignRequest` is where the end
 * date is put back to the close of its day, so "ends on the 28th" still means
 * the 28th is in.
 */
function toDateInput(value: string | null): string {
    return value === null ? '' : value.slice(0, 10);
}

/**
 * A blank row, with the first reward type already chosen.
 *
 * Pre-set rather than empty because a type is required either way, and a
 * select nobody has touched sending nothing back is a validation error about
 * a decision the form never asked anybody to make.
 */
function blankReward(): RewardRow {
    return {
        name: '',
        description: '',
        type: props.reward_types[0]?.value ?? '',
        quantity: '1',
        value: '',
        value_unit: '',
        validity_days: '',
        terms: '',
    };
}

/**
 * The saved definitions as editable rows.
 *
 * `loaded` is the quantity: on a draft there is no pool yet, so
 * `CampaignRewardData` reports the definition's own number there and the
 * other three counts are zero. On a published campaign these rows are never
 * shown as inputs, so the same reading does no harm.
 */
function savedRewards(campaign: App.Data.RewardCampaignData): RewardRow[] {
    return campaign.rewards.map((reward) => {
        return {
            name: reward.name,
            description: reward.description ?? '',
            type: reward.type,
            quantity: String(reward.loaded),
            /* Straight back out of the columns they went into.
               `CampaignRewardData` carries the raw figure and its unit
               alongside the formatted `value_label`, precisely so this can
               refill the boxes rather than parsing the display string. */
            value: reward.value ?? '',
            value_unit: reward.value_unit ?? '',
            validity_days:
                reward.validity_days === null
                    ? ''
                    : String(reward.validity_days),
            terms: reward.terms ?? '',
        };
    });
}

const form = useForm<CampaignForm>({
    name: props.campaign?.name ?? '',
    description: props.campaign?.description ?? '',
    starts_at: toDateInput(props.campaign?.starts_at ?? null),
    ends_at: toDateInput(props.campaign?.ends_at ?? null),
    /* One turn each is the honest default: a campaign that hands the same
       customer five goes is a decision somebody has to make on purpose. */
    max_shuffles_per_customer: String(
        props.campaign?.max_shuffles_per_customer ?? 1,
    ),
    minimum_purchase_amount: props.campaign?.minimum_purchase_amount ?? '',
    /* A campaign with an empty drawer cannot be published, so a new one opens
       with a row already there rather than an Add button and no clue that one
       is required. */
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

/**
 * Errors keyed against a row - `rewards.2.quantity` - which the typed error
 * bag has no name for. The bag itself is the same object either way; this is
 * only a way of asking it a question its type does not spell out.
 */
const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

function rewardError(index: number, field: string): string | undefined {
    return errors.value[`rewards.${index}.${field}`];
}

/** Empty means "not given", never the empty string the column would reject. */
function blankToNull(value: string): string | null {
    const trimmed = value.trim();

    return trimmed === '' ? null : trimmed;
}

/**
 * A cleared number box is nothing rather than zero. It matters for the
 * quantity in particular: zero would read as a reward that exists on the form
 * and not in the drawer, where nothing is the answer that gets the required
 * message the person actually needs.
 */
function numberOrNull(value: string): number | null {
    const trimmed = value.trim();

    return trimmed === '' ? null : Number(trimmed);
}

/**
 * The boxes hold strings and the request wants numbers, nulls and - past
 * publication - no rewards at all.
 *
 * The rewards are left out rather than sent and dropped. The server drops
 * them either way, so this changes nothing it decides; it keeps the payload
 * honest about what the screen is actually asking for, which is what somebody
 * reading a request log has to be able to trust.
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
                name: reward.name,
                description: blankToNull(reward.description),
                type: reward.type,
                quantity: numberOrNull(reward.quantity),
                value: blankToNull(reward.value),
                value_unit: blankToNull(reward.value_unit),
                validity_days: numberOrNull(reward.validity_days),
                terms: blankToNull(reward.terms),
            })),
        };
    });

    if (props.campaign === null) {
        form.post(store().url);

        return;
    }

    form.patch(update(props.campaign.id).url);
}

// -----------------------------------------------------------------------------
// Publishing, and the states after it
// -----------------------------------------------------------------------------

/**
 * Whatever `CampaignService` refused, keyed as `campaign` by the controller.
 *
 * Held here rather than read off the page's shared errors because these are
 * `router` posts rather than form submissions: the two buttons below have no
 * error bag of their own, and the message - "Chinese New Year is already
 * running", say - is the whole reason the press did nothing.
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
 * The states this campaign can be put into from where it stands.
 *
 * Kept in step with `CampaignService` by hand, which is the honest cost of
 * the transitions living in a service rather than on the enum: a draft has no
 * pool so it cannot be started, a closed campaign refuses every move, and
 * starting one that is already running is a press that does nothing. Offering
 * a button the service would refuse teaches people to ignore the row.
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

/*
 * The application asks "are you sure?" in exactly one voice, and only its text
 * is ours to set - which is why the confirm button below still says delete
 * where it is really asking about publishing. Worth the mismatch: a second,
 * bespoke dialog is a second thing to keep in step, and the sentence carries
 * the actual warning.
 */
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

    /* Only the two that close it. Starting and pausing are each undone by
       pressing the other one, so asking twice would be noise. */
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

/**
 * Only ever a draft nobody used: `RewardCampaignPolicy::delete` refuses a
 * published campaign, so `can.delete` is already false by the time there is
 * any history to lose.
 */
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

/*
 * A layout callback rather than a static object: one component serves
 * creating a campaign, editing a draft and reading a published one, and only
 * the page's own props know which of the three this is.
 */
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

<!--
  A promotion is its dates and its drawer, so both are filled in on one screen
  rather than saved and then gone back to - a campaign published with an empty
  drawer is the failure that split would invite.

  Publication is the line down the middle of this page. Before it everything
  is a proposal; after it the reward rows stop being inputs and start being a
  count of what is left, and the only actions are the ones that move the
  campaign through its life.
-->
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

                        <!-- Both optional, and they mean different things
                             empty: no start is "the moment it is published",
                             no end is "until somebody stops it". Publishing
                             reads the start date to decide whether the
                             campaign lands on active or scheduled. -->
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

                <!--
                  The heart of the screen, in two forms.

                  A draft is a list of proposals, so it is a list of inputs. A
                  published campaign's numbers have been written into the pool
                  and told to customers, so the same rows become a read of what
                  is left - and the four counts always reconcile, which is why
                  all four are printed rather than only "6 left". Somebody
                  reading that wants to know whether the other four were won or
                  withdrawn.
                -->
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

                        <p
                            v-if="reward.terms"
                            class="mt-3 text-xs text-muted-foreground"
                        >
                            {{ reward.terms }}
                        </p>
                    </div>
                </div>

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
                                <Label :for="`reward-${position}-name`">
                                    Name <span class="text-primary">*</span>
                                </Label>
                                <Input
                                    :id="`reward-${position}-name`"
                                    v-model="reward.name"
                                    class="mt-2.25"
                                    :disabled="readOnly"
                                    placeholder="e.g. 10% off your kitchen"
                                    :data-test="`reward-${position}-name`"
                                />
                                <InputError
                                    :message="rewardError(position, 'name')"
                                />
                            </div>

                            <div>
                                <Label :for="`reward-${position}-type`">
                                    Type <span class="text-primary">*</span>
                                </Label>
                                <Select
                                    v-model="reward.type"
                                    :disabled="readOnly"
                                >
                                    <SelectTrigger
                                        :id="`reward-${position}-type`"
                                        class="mt-2.25 w-full"
                                        :data-test="`reward-${position}-type`"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="option in props.reward_types"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError
                                    :message="rewardError(position, 'type')"
                                />
                            </div>

                            <!-- How many units of this reward go into the
                                 pool. The one number on the page that stops
                                 being editable the moment it is published. -->
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

                            <!-- The figure and its unit are one answer given
                                 in two boxes, and the request refuses either
                                 without the other: "10" is neither ten per
                                 cent nor ten shillings. Most rewards here are
                                 services and carry no figure at all, which is
                                 what the empty unit means. -->
                            <div>
                                <Label :for="`reward-${position}-value`">
                                    Figure
                                </Label>
                                <Input
                                    :id="`reward-${position}-value`"
                                    v-model="reward.value"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="mt-2.25"
                                    :disabled="readOnly"
                                    placeholder="e.g. 10"
                                    :data-test="`reward-${position}-value`"
                                />
                                <InputError
                                    :message="rewardError(position, 'value')"
                                />
                            </div>

                            <!-- A native select rather than the app's usual
                                 one: this is the single control on the screen
                                 that has to offer "nothing", and the styled
                                 select cannot hold an empty choice. -->
                            <div>
                                <Label :for="`reward-${position}-value-unit`">
                                    Read as
                                </Label>
                                <NativeSelect
                                    :id="`reward-${position}-value-unit`"
                                    v-model="reward.value_unit"
                                    class="mt-2.25 w-full"
                                    :disabled="readOnly"
                                    :data-test="`reward-${position}-value-unit`"
                                >
                                    <NativeSelectOption value="">
                                        No figure
                                    </NativeSelectOption>
                                    <NativeSelectOption
                                        v-for="unit in props.value_units"
                                        :key="unit.value"
                                        :value="unit.value"
                                    >
                                        {{ unit.label }}
                                    </NativeSelectOption>
                                </NativeSelect>
                                <InputError
                                    :message="
                                        rewardError(position, 'value_unit')
                                    "
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
                                <!-- Stamped onto the win rather than read
                                     later, so changing this never moves a
                                     deadline somebody already has. -->
                                <p class="mt-1.5 text-xs text-muted-foreground">
                                    Counted from the moment it is won. Empty
                                    means it never expires.
                                </p>
                            </div>

                            <div class="@2xl/page:col-span-2">
                                <Label :for="`reward-${position}-description`">
                                    Description
                                </Label>
                                <Textarea
                                    :id="`reward-${position}-description`"
                                    v-model="reward.description"
                                    class="mt-2.25"
                                    :disabled="readOnly"
                                    rows="2"
                                    placeholder="What the customer is actually getting."
                                    :data-test="`reward-${position}-description`"
                                />
                                <InputError
                                    :message="
                                        rewardError(position, 'description')
                                    "
                                />
                            </div>

                            <div class="@2xl/page:col-span-2">
                                <Label :for="`reward-${position}-terms`">
                                    Terms
                                </Label>
                                <Textarea
                                    :id="`reward-${position}-terms`"
                                    v-model="reward.terms"
                                    class="mt-2.25"
                                    :disabled="readOnly"
                                    rows="2"
                                    placeholder="What the reward is conditional on, as it will be printed."
                                    :data-test="`reward-${position}-terms`"
                                />
                                <InputError
                                    :message="rewardError(position, 'terms')"
                                />
                            </div>
                        </div>
                    </div>

                    <p
                        v-if="form.rewards.length === 0"
                        class="rounded-lg bg-muted/60 p-4 text-sm text-muted-foreground"
                        data-test="rewards-empty"
                    >
                        The drawer is empty. A campaign needs at least one
                        reward before anybody can win anything.
                    </p>

                    <div class="flex flex-wrap items-center gap-3">
                        <Button
                            v-if="!readOnly"
                            type="button"
                            variant="quiet"
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

                <Button as-child variant="quiet">
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
                <Button as-child variant="quiet">
                    <Link :href="index().url">Close</Link>
                </Button>
            </div>
        </form>

        <!--
          Its own card and its own writes, outside the form: publishing and the
          states after it answer to different rules from a name change, and a
          Save button that quietly also opened the doors is exactly the mistake
          the one-way rule exists to prevent.
        -->
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
                        variant="quiet"
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
