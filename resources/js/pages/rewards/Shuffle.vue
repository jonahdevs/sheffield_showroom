<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    Ban,
    CalendarDays,
    Check,
    CircleAlert,
    Clock,
    Copy,
    CopyCheck,
    Hourglass,
    PartyPopper,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import ShuffleCards from '@/components/ShuffleCards.vue';
import type { ShuffleCard } from '@/components/ShuffleCards.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { rewardIcon } from '@/lib/rewardIcons';
import { store } from '@/routes/rewards/shuffle';

/**
 * `campaign_closed` comes from `assertShuffleable()` via `$exception->reason`,
 * not from anything named in the controller body - dropping it from this union
 * draws a blank page the day a promotion is paused mid-queue.
 */
type ShuffleState =
    | 'ready'
    | 'won'
    | 'already_used'
    | 'expired'
    | 'cancelled'
    | 'campaign_closed'
    | 'pool_empty';

/**
 * The three keys are omitted deliberately, not by oversight: anybody holding a
 * photographed QR code can open this page, so the customer's name and the
 * redemption desk's fields must not be reachable here. Omitting them makes
 * reaching for one a compile error.
 */
type WonReward = Omit<
    App.Data.ShuffleRewardData,
    'customer_name' | 'redeemed_on' | 'redeemed_by'
>;

const props = defineProps<{
    state: ShuffleState;
    campaign: App.Data.ShuffleCampaignData;
    reward: WonReward | null;
    cards: ShuffleCard[];
}>();

const page = usePage();

/**
 * Read off the address bar rather than passed as a prop: a prop would put the
 * page's one secret into the serialised page data.
 */
const token = computed(() => page.url.split('/').pop() ?? '');

function claim(): void {
    router.post(
        store(token.value).url,
        {},
        {
            preserveScroll: true,
            /* Without this the redirect rebuilds the component and takes the
               cards, the phase and the tween with it: the reveal would appear
               with no shuffle in front of it. */
            preserveState: true,
            /* No loading bar across the top. The claim is a round trip, but to
               the customer this is a card being turned over, not a page being
               fetched - the progress bar is what made it read as one. The card
               itself shows the wait, in `ShuffleCards`. */
            showProgress: false,
        },
    );
}

const hasCopied = ref(false);
let copyTimer: number | null = null;

async function copyCode(): Promise<void> {
    if (props.reward === null) {
        return;
    }

    try {
        await navigator.clipboard.writeText(props.reward.code);

        hasCopied.value = true;

        if (copyTimer !== null) {
            window.clearTimeout(copyTimer);
        }

        copyTimer = window.setTimeout(() => (hasCopied.value = false), 2000);
    } catch {
        /* The clipboard is unavailable over plain HTTP and in some in-app
           browsers. The code carries `select-all` for a long press, so this
           is a convenience failing rather than the screen. */
    }
}

onBeforeUnmount(() => {
    if (copyTimer !== null) {
        window.clearTimeout(copyTimer);
    }
});

const NOTICES: Record<
    string,
    { icon: Component; title: string; body: string; keepsTurn?: boolean }
> = {
    already_used: {
        icon: Check,
        title: 'Already claimed',
        body: 'This reward has been claimed. If it was you, the code was shown on this screen at the time.',
    },
    expired: {
        icon: Clock,
        title: 'This link has expired',
        body: 'Reward links are good for a day. Ask at the counter if you think this is wrong.',
    },
    cancelled: {
        icon: Ban,
        title: 'No longer valid',
        body: 'This reward link was withdrawn.',
    },
    campaign_closed: {
        icon: Hourglass,
        title: 'The promotion has ended',
        body: 'This offer is no longer running.',
    },
    pool_empty: {
        icon: CircleAlert,
        title: 'Every reward has been won',
        body: 'There is nothing left in this promotion to win.',
        keepsTurn: true,
    },
};

const notice = computed(() => NOTICES[props.state] ?? NOTICES.expired);

const isPlayable = computed(
    () => props.state === 'ready' || props.state === 'won',
);

const heading = computed(() =>
    props.state === 'won' ? 'Congratulations!' : 'Try your luck',
);

/*
  The tab says what the page is, not what just happened to the person reading
  it: a browser history and a shared screenshot are both easier to place as
  "Your reward" than as "Congratulations!".
*/
const documentTitle = computed(() => {
    if (!isPlayable.value) {
        return notice.value.title;
    }

    return props.state === 'won' ? 'Your reward' : heading.value;
});

/**
 * Either end of a campaign may be open - a showroom stops one by pausing it,
 * not by waiting for a date - so all four cases are real. With neither end set
 * there is nothing worth printing: the badge beside the name already says
 * whether the promotion is open.
 */
const runsFor = computed<string | null>(() => {
    const { runs_from: from, runs_to: to } = props.campaign;

    if (from && to) {
        return `${from} – ${to}`;
    }

    if (to) {
        return `Until ${to}`;
    }

    if (from) {
        return `From ${from}`;
    }

    return null;
});

const STEPS = [
    'Spend the qualifying amount in the showroom.',
    'Staff hand you a code to scan on your phone.',
    'Shuffle the cards, then pick one.',
    'Show the reward code at the showroom to claim it.',
];

defineOptions({ layout: PublicLayout });
</script>

<template>
    <Head :title="documentTitle" />

    <div
        class="grid w-full items-start gap-5 xl:grid-cols-[19rem_minmax(0,1fr)_19rem]"
    >
        <!-- ===================== The promotion ===================== -->
        <div class="order-2 flex flex-col gap-4 xl:order-1">
            <!-- No panel heading: the promotion names itself, and "The
                 promotion" over the top of its own name was a label on a
                 label. -->
            <Card as="section" class="gap-0 p-0" data-test="panel-campaign">
                <div class="flex flex-col gap-3 p-5 text-left">
                    <div>
                        <div class="flex flex-wrap items-center gap-2.5">
                            <h2 class="text-lg leading-tight font-extrabold">
                                {{ props.campaign.name }}
                            </h2>
                            <!-- The same green the Rewards list gives a running
                                 campaign - see `statusTone` in
                                 `admin/rewards/Index.vue`. A tint rather than a
                                 fill, so it reads beside the name instead of
                                 shouting over it. -->
                            <Badge
                                variant="outline"
                                :class="
                                    props.campaign.is_running
                                        ? 'border-transparent bg-emerald-500/15 text-emerald-700 dark:text-emerald-400'
                                        : ''
                                "
                                data-test="campaign-status"
                            >
                                {{
                                    props.campaign.is_running
                                        ? 'Active'
                                        : 'Closed'
                                }}
                            </Badge>
                        </div>

                        <p
                            v-if="runsFor"
                            class="mt-1.5 flex items-center gap-1.5 text-xs font-bold"
                            data-test="runs-for"
                        >
                            <CalendarDays
                                class="size-3.5 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            {{ runsFor }}
                        </p>

                        <p
                            v-if="props.campaign.description"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            {{ props.campaign.description }}
                        </p>
                    </div>

                    <dl class="flex flex-col gap-2.5 text-xs">
                        <div
                            v-if="props.campaign.minimum_purchase"
                            class="flex justify-between gap-3"
                        >
                            <dt class="text-muted-foreground">
                                Qualifying purchase
                            </dt>
                            <dd class="text-right font-bold">
                                From {{ props.campaign.minimum_purchase }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">Shuffles</dt>
                            <dd class="text-right font-bold">
                                {{ props.campaign.shuffles_per_customer }} per
                                customer
                            </dd>
                        </div>
                    </dl>
                </div>
            </Card>

            <Card as="section" class="gap-0 p-0" data-test="panel-how">
                <div class="border-b border-divider px-5 py-3.5">
                    <h2
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        How it works
                    </h2>
                </div>

                <ol
                    class="flex list-none flex-col p-5 text-left text-xs text-muted-foreground"
                >
                    <li
                        v-for="(step, index) in STEPS"
                        :key="step"
                        class="relative flex gap-3 pb-4 last:pb-0"
                    >
                        <span
                            v-if="index < STEPS.length - 1"
                            class="absolute top-5 bottom-0 left-[0.625rem] w-px -translate-x-1/2 bg-border"
                            aria-hidden="true"
                        />

                        <span
                            class="relative z-10 flex size-5 shrink-0 items-center justify-center rounded-full bg-primary text-[0.625rem] font-bold text-primary-foreground"
                        >
                            {{ index + 1 }}
                        </span>
                        <span class="pt-0.5">{{ step }}</span>
                    </li>
                </ol>
            </Card>
        </div>

        <!-- ===================== The game ===================== -->
        <div
            class="order-1 flex flex-col items-center gap-6 text-center xl:order-2"
        >
            <!-- Names no reward, deliberately: the card is still turning over
                 when this arrives, and the turn is the reveal. What was won is
                 said below, in the panel that belongs to the answer. -->
            <h1
                v-if="isPlayable"
                class="flex items-center gap-2.5 text-2xl leading-tight font-extrabold"
                data-test="shuffle-heading"
            >
                <PartyPopper
                    v-if="props.state === 'won'"
                    class="size-7 text-primary"
                    aria-hidden="true"
                />
                {{ heading }}
            </h1>

            <ShuffleCards
                v-if="isPlayable && props.cards.length > 0"
                :cards="props.cards"
                :reward="props.reward"
                :playable="props.state === 'ready'"
                @pick="claim"
            />

            <div
                v-if="props.state === 'won' && props.reward"
                class="flex w-full flex-col items-center gap-4"
                data-test="reward-reveal"
            >
                <p class="text-lg leading-tight" data-test="reward-name">
                    You have won
                    <span class="font-extrabold text-primary">
                        {{ props.reward.name }}
                    </span>
                </p>

                <div class="flex flex-wrap items-center justify-center gap-2">
                    <Badge variant="secondary">{{
                        props.reward.type_label
                    }}</Badge>
                    <Badge v-if="props.reward.value" variant="outline">
                        {{ props.reward.value }}
                    </Badge>
                </div>

                <p
                    v-if="props.reward.description"
                    class="text-sm text-muted-foreground"
                >
                    {{ props.reward.description }}
                </p>

                <div class="flex flex-col items-center gap-1.5">
                    <span class="text-xs text-muted-foreground">
                        Show this code at the showroom
                    </span>

                    <!-- The code is the thing worth looking at, so it is
                         highlighted rather than framed: a box around it drew
                         the eye to the border. `select-all` keeps a long press
                         on a phone selecting the whole code and not a word of
                         it. -->
                    <div class="flex items-center gap-1.5">
                        <span
                            class="rounded-lg bg-primary/10 px-3 py-1 font-mono text-2xl font-extrabold tracking-[0.12em] break-all text-primary select-all sm:text-3xl"
                            data-test="reward-code"
                        >
                            {{ props.reward.code }}
                        </span>

                        <Button
                            variant="ghost"
                            size="icon-sm"
                            :aria-label="
                                hasCopied ? 'Code copied' : 'Copy the code'
                            "
                            :title="hasCopied ? 'Copied' : 'Copy the code'"
                            data-test="copy-code"
                            @click="copyCode"
                        >
                            <!-- `CopyCheck`, not a bare tick: it stays the same
                                 mark with the copy done, so the button does not
                                 appear to become a different control for two
                                 seconds. `Check` is still the notice icon for a
                                 reward already claimed, above. -->
                            <component
                                :is="hasCopied ? CopyCheck : Copy"
                                :class="hasCopied ? 'text-primary' : ''"
                            />
                        </Button>
                    </div>
                </div>

                <p class="text-xs text-muted-foreground">
                    Won {{ props.reward.won_on
                    }}<template v-if="props.reward.expires_on">
                        &middot; use it by
                        {{ props.reward.expires_on }}</template
                    >
                </p>

                <p
                    v-if="props.reward.terms"
                    class="text-xs text-muted-foreground"
                    data-test="reward-terms"
                >
                    {{ props.reward.terms }}
                </p>

                <p class="text-xs text-faint">
                    Take a screenshot &mdash; this link expires, the code does
                    not.
                </p>
            </div>

            <div
                v-if="!isPlayable"
                class="flex w-full flex-col items-center gap-3"
                data-test="shuffle-notice"
            >
                <component
                    :is="notice.icon"
                    class="size-8 text-muted-foreground"
                />
                <span class="text-lg font-bold">{{ notice.title }}</span>
                <p class="text-sm text-muted-foreground">{{ notice.body }}</p>

                <p
                    v-if="notice.keepsTurn"
                    class="w-full rounded-lg bg-muted/60 p-3 text-xs text-muted-foreground"
                    data-test="keeps-turn"
                >
                    Your turn is still yours. Nothing has been used up &mdash;
                    ask at the counter.
                </p>
            </div>
        </div>

        <!-- ===================== What you could win ===================== -->
        <!--
          Names the rewards but never how many of each are left: anybody
          holding a link can open this page, and remaining counts would let
          them work out the odds. `ShuffleCampaignData` is a separate class
          from `RewardCampaignData` for exactly this reason.
        -->
        <div class="order-3 flex flex-col gap-4">
            <Card as="section" class="gap-0 p-0" data-test="panel-rewards">
                <div class="border-b border-divider px-5 py-3.5">
                    <h2
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        What you could win
                    </h2>
                </div>

                <ul class="flex list-none flex-col divide-y divide-divider">
                    <li
                        v-for="card in props.cards"
                        :key="card.name"
                        class="flex items-center gap-3 px-5 py-3 text-left"
                    >
                        <component
                            :is="rewardIcon(card.type)"
                            class="size-6 shrink-0 text-primary"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="block text-xs font-bold">
                                {{ card.name }}
                            </span>
                            <span class="block text-xs text-muted-foreground">
                                {{ card.type_label }}
                            </span>
                        </span>
                        <Badge v-if="card.value" variant="outline">
                            {{ card.value }}
                        </Badge>
                    </li>
                </ul>
            </Card>

            <Card as="section" class="gap-0 p-0" data-test="panel-terms">
                <div class="border-b border-divider px-5 py-3.5">
                    <h2
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        Terms and conditions
                    </h2>
                </div>

                <!--
                  Composed by `ShuffleCampaignData::termsFor()`, not written
                  here: one term names the campaign's shuffle limit, so a
                  hardcoded "only once" would contradict the enforced rule.
                -->
                <ul
                    class="flex list-none flex-col gap-2.5 p-5 text-left text-xs text-muted-foreground"
                >
                    <li
                        v-for="term in props.campaign.terms"
                        :key="term"
                        class="flex gap-2.5"
                    >
                        <span
                            class="mt-1.5 size-1 shrink-0 rounded-full bg-muted-foreground"
                            aria-hidden="true"
                        />
                        <span>{{ term }}</span>
                    </li>
                </ul>
            </Card>
        </div>
    </div>
</template>
