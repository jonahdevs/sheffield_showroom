<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Ban, Check, CircleAlert, Clock, Copy, Hourglass } from '@lucide/vue';
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
 * Every state `ShuffleController::show()` can hand this page.
 *
 * `campaign_closed` is easy to miss reading the controller: `assertShuffleable()`
 * raises it when the campaign is paused or out of its dates, and the controller
 * passes `$exception->reason` straight through. Leaving it off would draw a
 * customer a blank page on the one day a promotion is switched off mid-queue.
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
 * The part of `ShuffleRewardData` this page may read.
 *
 * Narrowed on the way in. `customer_name` is always null on this route by
 * design, and `redeemed_on`/`redeemed_by` describe the redemption desk's side
 * of it; none of the three belongs on a screen anybody holding a photographed
 * QR code can open. Taking them off the type makes reaching for one a compile
 * error rather than something a reviewer has to catch.
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
 * The token is read off the address bar rather than sent as a prop.
 *
 * It is already in the URL - that is how the customer got here - and a prop
 * would be a second copy of the one secret this page has, sitting in the page
 * data where anything that serialises props would carry it.
 */
const token = computed(() => page.url.split('/').pop() ?? '');

// -----------------------------------------------------------------------------
// The reveal
// -----------------------------------------------------------------------------

/**
 * The tap, sent on to the server.
 *
 * The table itself is `ShuffleCards`, which knows how to deal, turn and pick
 * but nothing about routes. Sending the request from here is what lets the
 * showroom screen use the same table and post somewhere else.
 */
function claim(): void {
    router.post(
        store(token.value).url,
        {},
        {
            preserveScroll: true,
            /* Without this Inertia rebuilds the component when the redirect
               lands, and the cards, the phase and the tween go with it - the
               reveal would appear with no shuffle in front of it. */
            preserveState: true,
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
        /* The clipboard is unavailable over plain HTTP and inside a few in-app
           browsers. The code carries `select-all`, which is what a long press
           lands on, so this is a convenience failing rather than the screen. */
    }
}

onBeforeUnmount(() => {
    if (copyTimer !== null) {
        window.clearTimeout(copyTimer);
    }
});

/*
 * The states that are not a game: somebody else already used this link, it ran
 * out, it was withdrawn, or the drawer is empty. One shape, four messages.
 */
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

/** Whether the table is on screen at all. */
const isPlayable = computed(
    () => props.state === 'ready' || props.state === 'won',
);

const heading = computed(() =>
    props.state === 'won' ? 'Your reward' : 'Try your luck',
);

/**
 * What the browser tab says.
 *
 * The heading when there is a game, and the notice's own title when there is
 * not - a tab reading "Try your luck" over a page that says the link expired
 * is the sort of thing somebody screenshots.
 */
const documentTitle = computed(() =>
    isPlayable.value ? heading.value : notice.value.title,
);

/**
 * When the promotion runs, as a sentence rather than two fields.
 *
 * A campaign is usually open at one end or both - a showroom stops one by
 * pausing it, not by waiting for a date - so "Until Sunday" and "Running now"
 * are the common answers, and a pair of empty date labels is the wrong way to
 * say either.
 */
const runsFor = computed(() => {
    const { runs_from: from, runs_to: to } = props.campaign;

    if (from && to) {
        return `${from} to ${to}`;
    }

    if (to) {
        return `Until ${to}`;
    }

    if (from) {
        return `From ${from}`;
    }

    return 'Running now';
});

/**
 * How the promotion works, in the order it happens to somebody.
 *
 * The fourth step is the one worth having: the link on this page expires
 * within a day, and the code is what the reward is actually claimed with,
 * weeks later, at a different desk.
 */
const STEPS = [
    'Spend the qualifying amount in the showroom.',
    'Staff hand you a code to scan on your phone.',
    'Shuffle the cards, then pick one.',
    'Show the reward code at the showroom to claim it.',
];

/** The rewards that actually carry terms, which is what the panel prints. */
const termed = computed(() => props.cards.filter((card) => card.terms));

defineOptions({ layout: PublicLayout });
</script>

<!--
  The customer's screen, on their own phone, at the counter.

  The cards are dealt face up before anything happens, so nobody is asked to
  pick blind from a table they never saw. Then they turn, they move, and one
  gets tapped.

  What is deliberately absent: the customer's name, the sale that earned this,
  and how many of anything are left. The last of those is the one worth
  spelling out - printing live inventory would let somebody work out the odds
  from their phone, and this is a promotion rather than a betting product.
-->
<template>
    <Head :title="documentTitle" />

    <!--
      Three columns on a monitor, one on a phone.

      The panels are a fixed width and the table takes the rest, so the grid
      fills the layout's `container` rather than centring a narrower track
      inside it - which is what puts its outer edges under the logo in the bar
      above instead of floating somewhere short of them.

      The panels either side are what a customer reads while deciding, and what
      they read again afterwards to find out what their reward actually
      entitles them to. The cards inside the middle column centre themselves,
      so the extra width it absorbs on a wide screen becomes air around the
      table rather than enormous cards.

      The order classes matter more than the grid does. Stacked on a phone the
      game has to come first - somebody who has just scanned a code at a
      counter wants the cards, not the small print - so the middle column is
      `order-1` until there is room to put it back in the middle.
    -->
    <div
        class="grid w-full items-start gap-5 xl:grid-cols-[19rem_minmax(0,1fr)_19rem]"
    >
        <!-- The promotion itself. -->
        <div class="order-2 flex flex-col gap-4 xl:order-1">
            <Card as="section" class="gap-0 p-0" data-test="panel-campaign">
                <div class="border-b border-divider px-5 py-3.5">
                    <h2
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        The promotion
                    </h2>
                </div>

                <div class="flex flex-col gap-3 p-5 text-left">
                    <div>
                        <span class="text-lg leading-tight font-extrabold">
                            {{ props.campaign.name }}
                        </span>
                        <p
                            v-if="props.campaign.description"
                            class="mt-1 text-xs text-muted-foreground"
                        >
                            {{ props.campaign.description }}
                        </p>
                    </div>

                    <dl class="flex flex-col gap-2.5 text-xs">
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">Running</dt>
                            <dd
                                class="text-right font-bold"
                                data-test="runs-for"
                            >
                                {{ runsFor }}
                            </dd>
                        </div>
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

            <!-- Four steps, because the fourth is the one people forget: the
                 code is what the reward is claimed with, weeks later. -->
            <Card as="section" class="gap-0 p-0" data-test="panel-how">
                <div class="border-b border-divider px-5 py-3.5">
                    <h2
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        How it works
                    </h2>
                </div>

                <!--
                  A stepper rather than a numbered list: the rule joining the
                  markers is what says these happen in order and that there is
                  more after the one you are reading.

                  Drawn here rather than with reka's `Stepper`, which exists in
                  the bundle. That primitive is built for a wizard - it carries
                  a model value, triggers and per-step validity, and every one
                  of those is state. This is four sentences that never change
                  and that nobody navigates, so it is a list with a line down
                  it, which is all a reader needs it to be.

                  The connector is absolutely positioned from just under one
                  marker to the bottom of its own row, so it meets the next
                  marker whatever the text above it wraps to. The last step
                  does not draw one - a line trailing off under the final item
                  promises a fifth step that is not coming.
                -->
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

        <!-- The game. -->
        <div
            class="order-1 flex flex-col items-center gap-6 text-center xl:order-2"
        >
            <!--
              No campaign name over the table. The panel beside it already
              names the promotion and says when it runs, and repeating it here
              spent the largest words on the screen saying something the
              customer had just read.

              Only shown while there is a game: an expired or already-claimed
              link draws its own notice below, and "Try your luck" over the top
              of "This link has expired" would be the page arguing with itself.

              Reads off the server's state rather than the table's phase - the
              table owns how far through the game it is, and a heading that had
              to know would be a second reason for this page to reach into it.
            -->
            <h1
                v-if="isPlayable"
                class="text-2xl leading-tight font-extrabold"
                data-test="shuffle-heading"
            >
                {{ heading }}
            </h1>

            <!-- The table itself, shared with the showroom screen. -->
            <ShuffleCards
                v-if="isPlayable && props.cards.length > 0"
                :cards="props.cards"
                :reward="props.reward"
                :playable="props.state === 'ready'"
                @pick="claim"
            />

            <!--
          What they won. Under the table rather than instead of it, so the card
          they turned over stays on screen next to its own answer.
        -->
            <div
                v-if="props.state === 'won' && props.reward"
                class="flex w-full flex-col items-center gap-4"
                data-test="reward-reveal"
            >
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

                <!-- The code is the thing they leave with. Everything else on this
                 screen is smaller than it on purpose. -->
                <div
                    class="w-full rounded-xl border-2 border-dashed border-primary/40 bg-card p-4"
                >
                    <span class="block text-xs text-muted-foreground">
                        Show this code at the showroom
                    </span>
                    <span
                        class="mt-1.5 block font-mono text-3xl font-extrabold tracking-[0.12em] break-all select-all sm:text-4xl"
                        data-test="reward-code"
                    >
                        {{ props.reward.code }}
                    </span>
                </div>

                <Button
                    variant="outline"
                    class="w-full"
                    data-test="copy-code"
                    @click="copyCode"
                >
                    <component :is="hasCopied ? Check : Copy" />
                    {{ hasCopied ? 'Copied' : 'Copy code' }}
                </Button>

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

            <!-- Everything that is not a game. -->
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

        <!-- What is on offer, and the small print behind it. -->
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

                <!-- No counts anywhere in that list, on purpose - see
                     `ShuffleCampaignData`. What is said instead is the honest
                     version of the same thing. -->
                <p class="border-t border-divider px-5 py-3 text-xs text-faint">
                    One of these, drawn from what is still unclaimed.
                </p>
            </Card>

            <Card
                v-if="termed.length > 0"
                as="section"
                class="gap-0 p-0"
                data-test="panel-terms"
            >
                <div class="border-b border-divider px-5 py-3.5">
                    <h2
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        Terms and conditions
                    </h2>
                </div>

                <!-- The terms the campaign actually carries, per reward.
                     Nothing is written here that an administrator did not type
                     into the campaign: invented small print is small print
                     nobody agreed to. -->
                <dl class="flex flex-col gap-3 p-5 text-left text-xs">
                    <div v-for="card in termed" :key="card.name">
                        <dt class="font-bold">{{ card.name }}</dt>
                        <dd class="mt-0.5 text-muted-foreground">
                            {{ card.terms }}
                        </dd>
                    </div>
                </dl>
            </Card>
        </div>
    </div>
</template>
