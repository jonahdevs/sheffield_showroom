<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    AlarmClock,
    ArrowRight,
    Gift,
    Hourglass,
    Megaphone,
    Shuffle,
    TicketCheck,
} from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import ApexChart from '@/components/ApexChart.vue';
import DateRangePicker from '@/components/DateRangePicker.vue';
import DonutBreakdown from '@/components/DonutBreakdown.vue';
import PanelEmpty from '@/components/PanelEmpty.vue';
import StatTile from '@/components/StatTile.vue';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { CHART_PALETTE, useChartTheme } from '@/composables/useChartTheme';
import { useFilters } from '@/composables/useFilters';
import { dashboard } from '@/routes';
import { edit, index as campaignsIndex } from '@/routes/admin/rewards';
import { index as overviewIndex } from '@/routes/admin/rewards/overview';
import { index as winnersIndex } from '@/routes/admin/rewards/winners';

const props = defineProps<{
    range: App.Data.DashboardRangeData;
    presets: { value: string; label: string }[];
    headline: App.Data.RewardHeadlineData | null;
    drawer: App.Data.RewardDrawerRowData[];
    stats: App.Data.DashboardStatData[];
    /**
     * `DashboardTrendPointData` is shared with the dashboard's visit line, so
     * the win count arrives under the name `visits`.
     */
    wins: App.Data.DashboardTrendPointData[];
    collection_rate: number | null;
    outcomes: App.Data.DashboardSliceData[];
    expiring: App.Data.RewardExpiringRowData[];
    expiring_total: number;
    expiring_within_days: number;
    past: App.Data.RewardCampaignSummaryData[];
}>();

/*
  `only` deliberately lists just the five props the window moves. The headline,
  drawer, expiry panel and history answer about now and about the whole
  programme, so adding them here would buy four aggregate queries per click for
  nothing.
*/
const { processing, apply } = useFilters({
    url: overviewIndex.url(),
    initial: {
        range: props.range.preset,
        from: props.range.preset === 'custom' ? props.range.from : '',
        to: props.range.preset === 'custom' ? props.range.to : '',
    },
    blank: { range: 'last_7_days', from: '', to: '' },
    only: ['range', 'stats', 'wins', 'collection_rate', 'outcomes'],
});

function chooseWindow(from: string, to: string): void {
    apply({ range: 'custom', from, to });
}

/**
 * The dates are cleared rather than left behind, or a bookmarked "this month"
 * would reopen the days it happened to mean when it was saved.
 */
function choosePreset(range: string): void {
    apply({ range, from: '', to: '' });
}

const windowLabel = computed(
    () =>
        props.presets.find((preset) => preset.value === props.range.preset)
            ?.label ?? props.range.label,
);

const { theme } = useChartTheme();

// =========================================================================
// The running campaign
// =========================================================================

/** Kept in step with the same function on `Index.vue`, case for case. */
function statusTone(status: string): string {
    switch (status) {
        case 'draft':
            return 'border-transparent bg-muted text-faint';
        case 'scheduled':
            return 'border-transparent bg-sky-500/15 text-sky-700 dark:text-sky-400';
        case 'active':
            return 'border-transparent bg-emerald-500/15 text-emerald-700 dark:text-emerald-400';
        case 'paused':
            return 'border-transparent bg-amber-500/15 text-amber-700 dark:text-amber-400';
        case 'cancelled':
            return 'border-transparent bg-destructive/10 text-destructive';
        default:
            return 'border-transparent bg-muted text-muted-foreground';
    }
}

const remaining = computed<string | null>(() => {
    const days = props.headline?.days_remaining ?? null;

    if (days === null) {
        return null;
    }

    if (days === 0) {
        return 'Ends today';
    }

    return `${days} ${days === 1 ? 'day' : 'days'} left`;
});

/**
 * `loaded = available + claimed + void`, so void is deliberately left out here
 * and reported on the campaigns table instead: it counts what an administrator
 * withdrew, not what the promotion spent.
 */
const drawerFigures = computed(() => {
    const campaign = props.headline?.campaign;

    if (campaign === undefined) {
        return [];
    }

    return [
        { label: 'Loaded', value: campaign.loaded },
        { label: 'Available', value: campaign.available },
        { label: 'Claimed', value: campaign.claimed },
        { label: 'Turns', value: campaign.turns_given },
    ];
});

// =========================================================================
// Wins over time
// =========================================================================

const winsInRange = computed(() =>
    props.wins.reduce((total, point) => total + point.visits, 0),
);

const winsSeries = computed(() => [
    { name: 'Wins', data: props.wins.map((point) => point.visits) },
]);

/* The dashboard's trend options verbatim; the reasoning is on `Dashboard.vue`. */
const winsOptions = computed<ApexCharts.ApexOptions>(() => ({
    chart: {
        type: 'area',
        height: 264,
        fontFamily: 'inherit',
        toolbar: { show: false },
        zoom: { enabled: false },
        animations: { enabled: true, speed: 400 },
    },
    colors: [theme.value.accent],
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 2.5 },
    fill: {
        type: 'gradient',
        gradient: {
            type: 'vertical',
            shade: 'light',
            shadeIntensity: 0,
            inverseColors: false,
            gradientToColors: [theme.value.accent],
            opacityFrom: 0.28,
            opacityTo: 0,
            stops: [0, 88],
        },
    },
    markers: { size: 0, strokeWidth: 0, hover: { size: 5 } },
    grid: {
        borderColor: theme.value.grid,
        strokeDashArray: 4,
        xaxis: { lines: { show: false } },
        padding: { left: 4, right: 8, top: 0, bottom: 0 },
    },
    xaxis: {
        categories: props.wins.map((point) => point.label),
        tickAmount: Math.min(props.wins.length, 7),
        axisBorder: { show: false },
        axisTicks: { show: false },
        tooltip: { enabled: false },
        labels: {
            rotate: 0,
            hideOverlappingLabels: true,
            style: { colors: theme.value.label, fontSize: '11px' },
        },
    },
    yaxis: {
        min: 0,
        tickAmount: 4,
        forceNiceScale: true,
        labels: {
            style: { colors: theme.value.label, fontSize: '11px' },
            formatter: (value: number) => String(Math.round(value)),
        },
    },
    tooltip: {
        theme: theme.value.isDark ? 'dark' : 'light',
        x: { show: true },
        y: {
            formatter: (value: number) =>
                `${value} ${value === 1 ? 'reward' : 'rewards'}`,
        },
    },
}));

// =========================================================================
// The KPI row
// =========================================================================

/** Keyed by the server's `key`, so a new figure is one line here. */
const TILES: Record<string, { icon: Component; colour: string }> = {
    turns: { icon: Shuffle, colour: CHART_PALETTE[0] },
    won: { icon: Gift, colour: CHART_PALETTE[1] },
    redeemed: { icon: TicketCheck, colour: CHART_PALETTE[2] },
    unclaimed: { icon: Hourglass, colour: CHART_PALETTE[3] },
};

function tile(key: string) {
    return TILES[key] ?? { icon: Gift, colour: CHART_PALETTE[8] };
}

// =========================================================================
// The drawer
// =========================================================================

/**
 * Bars are scaled against the fullest bar, not against 100%. A young campaign
 * has spent single-digit percentages of everything, and five near-invisible
 * bars cannot answer which one is going fastest.
 */
const fastestShare = computed(() =>
    Math.max(1, ...props.drawer.map((row) => row.claimed_share)),
);

function barWidth(share: number): string {
    return `${Math.max((share / fastestShare.value) * 100, 6)}%`;
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Rewards overview' },
        ],
    },
});
</script>

<template>
    <Head title="Reward overview" />

    <div class="flex flex-col gap-5">
        <div class="flex flex-wrap items-start gap-4">
            <div class="flex min-w-0 flex-1 flex-col gap-2">
                <h1 class="text-2xl leading-tight">Reward overview</h1>
                <p class="text-sm text-muted-foreground">
                    How the reward programme is doing over
                    {{ props.range.label }}, and what every promotion before
                    this one did.
                </p>
            </div>

            <DateRangePicker
                :from="props.range.from"
                :to="props.range.to"
                :label="windowLabel"
                :presets="props.presets"
                :active="props.range.preset"
                data-test="overview-range"
                @update="chooseWindow"
                @preset="choosePreset"
            />
        </div>

        <!-- ===================== The running campaign ===================== -->
        <Card
            v-if="props.headline !== null"
            as="section"
            class="min-w-0 gap-0 overflow-hidden p-0"
            data-test="overview-headline"
        >
            <div class="flex flex-wrap items-start gap-4 p-5">
                <span
                    class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
                    aria-hidden="true"
                >
                    <Megaphone class="size-5" />
                </span>

                <div class="min-w-0 flex-1">
                    <p
                        class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                    >
                        Running now
                    </p>

                    <div class="mt-1 flex flex-wrap items-center gap-2.5">
                        <h2
                            class="min-w-0 truncate text-lg leading-tight font-bold"
                            data-test="headline-name"
                        >
                            {{ props.headline.campaign.name }}
                        </h2>

                        <span
                            class="rounded border px-1.5 py-0.5 text-[0.6875rem]"
                            :class="statusTone(props.headline.campaign.status)"
                            data-test="headline-status"
                        >
                            {{ props.headline.campaign.status_label }}
                        </span>

                        <span
                            v-if="props.headline.dormant_reason"
                            class="text-xs font-semibold text-amber-700 dark:text-amber-400"
                            data-test="headline-dormant"
                        >
                            {{ props.headline.dormant_reason }}
                        </span>

                        <span
                            v-else-if="remaining"
                            class="text-xs text-muted-foreground tabular-nums"
                            data-test="headline-remaining"
                        >
                            {{ remaining }}
                        </span>
                    </div>

                    <p class="mt-1 truncate text-xs text-faint">
                        {{
                            props.headline.campaign.description ??
                            'No description.'
                        }}
                    </p>
                </div>

                <div
                    class="flex flex-wrap items-center gap-x-5 gap-y-2"
                    data-test="headline-drawer"
                >
                    <div v-for="figure in drawerFigures" :key="figure.label">
                        <p class="text-lg leading-tight font-bold tabular-nums">
                            {{ figure.value }}
                        </p>
                        <p class="text-xs text-faint">{{ figure.label }}</p>
                    </div>

                    <!-- Never permission-gated: reading a campaign needs only
                         `rewards.view`, and the form locks its own fields. -->
                    <Button as-child variant="outline" size="sm">
                        <Link
                            :href="edit(props.headline.campaign.id).url"
                            data-test="headline-open"
                        >
                            Open campaign
                            <ArrowRight />
                        </Link>
                    </Button>
                </div>
            </div>
        </Card>

        <!-- `flex-none` overrides `Empty`'s own `flex-1`: the Card around it is
             a flex column with no height of its own, so an item told to fill
             the free space collapses. -->
        <Card v-else class="min-w-0 gap-0 overflow-hidden p-0">
            <Empty class="flex-none" data-test="overview-no-campaign">
                <EmptyHeader>
                    <EmptyMedia variant="icon">
                        <Megaphone />
                    </EmptyMedia>
                    <EmptyTitle>No promotion is running</EmptyTitle>
                    <EmptyDescription>
                        Nothing is being handed out at the moment, which is the
                        ordinary state between promotions. The figures below
                        still report on everything that has been.
                    </EmptyDescription>
                </EmptyHeader>
            </Empty>
        </Card>

        <div
            class="flex flex-col gap-4 transition-opacity"
            :class="processing ? 'opacity-60' : ''"
            :aria-busy="processing"
        >
            <div class="grid gap-4 @lg/page:grid-cols-2 @3xl/page:grid-cols-4">
                <StatTile
                    v-for="stat in props.stats"
                    :key="stat.key"
                    :stat="stat"
                    :icon="tile(stat.key).icon"
                    :colour="tile(stat.key).colour"
                    :comparison="`vs previous ${props.range.days} days`"
                />
            </div>

            <div
                class="grid gap-4 @2xl/page:grid-cols-2 @5xl/page:grid-cols-12"
            >
                <!-- ===================== Wins over time ===================== -->
                <Card
                    as="section"
                    class="gap-0 p-0 @5xl/page:col-span-7"
                    data-test="wins-over-time"
                >
                    <div
                        class="flex items-center justify-between gap-3 border-b border-divider px-5 py-3.5"
                    >
                        <h2
                            class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                        >
                            Rewards won
                        </h2>
                        <span class="text-xs text-faint tabular-nums">
                            {{ winsInRange }} in total
                        </span>
                    </div>

                    <PanelEmpty v-if="winsInRange === 0" :height="264">
                        Nothing was won over {{ props.range.label }}.
                    </PanelEmpty>

                    <div v-else class="p-3 pt-4">
                        <ApexChart
                            :options="winsOptions"
                            :series="winsSeries"
                            label="Rewards won per day over the selected period"
                        />
                    </div>
                </Card>

                <!-- ===================== What became of them ===================== -->
                <Card
                    as="section"
                    class="gap-0 p-0 @5xl/page:col-span-5"
                    data-test="collection"
                >
                    <div
                        class="flex items-center justify-between gap-3 border-b border-divider px-5 py-3.5"
                    >
                        <h2
                            class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                        >
                            Collected
                        </h2>

                        <span
                            class="text-xs font-bold text-faint tabular-nums"
                            data-test="collection-rate"
                        >
                            {{
                                props.collection_rate === null
                                    ? 'Nothing won yet'
                                    : `${props.collection_rate}% of wins`
                            }}
                        </span>
                    </div>

                    <PanelEmpty
                        v-if="props.outcomes.length === 0"
                        :height="210"
                    >
                        Nothing was won over {{ props.range.label }}, so there
                        is nothing to have been collected.
                    </PanelEmpty>

                    <DonutBreakdown
                        v-else
                        :slices="props.outcomes"
                        :total="winsInRange"
                        unit="rewards"
                        label="Rewards won over the period, divided by what became of them"
                    />
                </Card>
            </div>

            <div
                class="grid gap-4 @2xl/page:grid-cols-2 @5xl/page:grid-cols-12"
            >
                <!-- ===================== Going fastest ===================== -->
                <Card
                    as="section"
                    class="gap-0 p-0 @5xl/page:col-span-5"
                    data-test="drawer-pace"
                >
                    <div class="border-b border-divider px-5 py-3.5">
                        <h2
                            class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                        >
                            Going fastest
                        </h2>
                    </div>

                    <!-- Counted off the pool, not off the selected window: what
                         is left in a drawer is a running total. -->
                    <PanelEmpty v-if="props.drawer.length === 0" :height="210">
                        {{
                            props.headline === null
                                ? 'No promotion is running, so there is no drawer to spend.'
                                : 'This campaign has nothing in its drawer yet.'
                        }}
                    </PanelEmpty>

                    <ul v-else class="divide-y divide-divider">
                        <li
                            v-for="row in props.drawer"
                            :key="row.id"
                            class="flex items-center gap-3 px-5 py-3"
                            :data-test="`drawer-row-${row.id}`"
                        >
                            <span
                                class="w-28 shrink-0 truncate text-xs font-bold @lg/page:w-36"
                                :title="row.name"
                            >
                                {{ row.name }}
                            </span>

                            <span
                                class="h-2 min-w-8 flex-1 overflow-hidden rounded-full bg-muted"
                            >
                                <span
                                    class="block h-full rounded-full bg-primary"
                                    :style="{
                                        width: barWidth(row.claimed_share),
                                    }"
                                />
                            </span>

                            <span
                                class="shrink-0 text-right text-xs tabular-nums"
                                :title="`${row.claimed} of ${row.loaded} won, ${row.available} still available`"
                            >
                                <span class="font-bold">
                                    {{ row.claimed_share }}%
                                </span>
                                <span class="text-faint">
                                    ({{ row.claimed }}/{{ row.loaded }})
                                </span>
                            </span>
                        </li>
                    </ul>
                </Card>

                <!-- ===================== Closing soon ===================== -->
                <Card
                    as="section"
                    class="gap-0 p-0 @5xl/page:col-span-7"
                    data-test="expiring-soon"
                >
                    <div
                        class="flex items-center justify-between gap-3 border-b border-divider px-5 py-3.5"
                    >
                        <h2
                            class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                        >
                            Lapsing within {{ props.expiring_within_days }} days
                        </h2>

                        <Link
                            :href="winnersIndex().url"
                            class="flex items-center gap-1 text-xs font-bold text-primary hover:underline"
                            data-test="view-all-rewards"
                        >
                            All rewards
                            <ArrowRight class="size-3.5" />
                        </Link>
                    </div>

                    <PanelEmpty
                        v-if="props.expiring.length === 0"
                        :height="210"
                    >
                        Nothing lapses in the next
                        {{ props.expiring_within_days }} days.
                    </PanelEmpty>

                    <div v-else>
                        <div class="divide-y divide-divider">
                            <div
                                v-for="reward in props.expiring"
                                :key="reward.id"
                                class="grid grid-cols-[minmax(0,1fr)_110px_92px] items-center gap-3 px-5 py-3"
                                :data-test="`expiring-${reward.id}`"
                            >
                                <span
                                    class="truncate text-xs font-bold"
                                    :title="reward.customer_name"
                                >
                                    {{ reward.customer_name }}
                                </span>

                                <span
                                    class="truncate text-xs text-muted-foreground tabular-nums"
                                >
                                    {{ reward.code }}
                                </span>

                                <span
                                    class="text-right text-xs tabular-nums"
                                    :class="
                                        reward.days_left <= 2
                                            ? 'font-bold text-amber-700 dark:text-amber-400'
                                            : 'text-faint'
                                    "
                                    :title="`Lapses on ${reward.expires_on}`"
                                >
                                    {{
                                        reward.days_left === 0
                                            ? 'Today'
                                            : `${reward.days_left}d left`
                                    }}
                                </span>
                            </div>
                        </div>

                        <p
                            v-if="props.expiring_total > props.expiring.length"
                            class="border-t border-divider px-5 py-3 text-xs text-faint"
                            data-test="expiring-total"
                        >
                            {{ props.expiring_total }} rewards lapse within
                            {{ props.expiring_within_days }} days in all.
                        </p>
                    </div>
                </Card>
            </div>
        </div>

        <!-- ===================== Past campaigns ===================== -->
        <Card
            as="section"
            class="min-w-0 gap-0 overflow-hidden p-0"
            data-test="past-campaigns"
        >
            <div class="border-b border-divider px-5 py-3.5">
                <h2
                    class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                >
                    Promotions before this one
                </h2>
            </div>

            <Empty
                v-if="props.past.length === 0"
                class="flex-none"
                data-test="past-empty"
            >
                <EmptyHeader>
                    <EmptyMedia variant="icon">
                        <AlarmClock />
                    </EmptyMedia>
                    <EmptyTitle>No promotion has finished yet</EmptyTitle>
                    <EmptyDescription>
                        There is nothing behind the current campaign to compare
                        it against. This table fills itself in as promotions
                        end.
                    </EmptyDescription>
                </EmptyHeader>
            </Empty>

            <div v-else class="overflow-x-auto">
                <div
                    class="grid min-w-[860px] grid-cols-[minmax(0,1.4fr)_minmax(0,200px)_110px_80px_80px_90px_96px] items-center gap-4 border-b border-divider bg-muted/50 px-5 py-3 text-xs font-semibold"
                >
                    <span>Campaign</span>
                    <span>Ran</span>
                    <span>Status</span>
                    <span class="text-right">Loaded</span>
                    <span class="text-right">Won</span>
                    <span class="text-right">Redeemed</span>
                    <span class="text-right">Collected</span>
                </div>

                <div class="divide-y divide-divider">
                    <Link
                        v-for="campaign in props.past"
                        :key="campaign.id"
                        :href="edit(campaign.id).url"
                        class="grid min-w-[860px] grid-cols-[minmax(0,1.4fr)_minmax(0,200px)_110px_80px_80px_90px_96px] items-center gap-4 px-5 py-3 hover:bg-muted/40"
                        :data-test="`past-campaign-${campaign.id}`"
                    >
                        <span
                            class="truncate text-xs font-bold"
                            :title="campaign.name"
                        >
                            {{ campaign.name }}
                        </span>

                        <span
                            class="truncate text-xs text-muted-foreground tabular-nums"
                        >
                            {{ campaign.ran }}
                        </span>

                        <span
                            class="w-fit rounded border px-1.5 py-0.5 text-[0.6875rem]"
                            :class="statusTone(campaign.status)"
                        >
                            {{ campaign.status_label }}
                        </span>

                        <span class="text-right text-xs tabular-nums">
                            {{ campaign.loaded }}
                        </span>
                        <span class="text-right text-xs tabular-nums">
                            {{ campaign.won }}
                        </span>
                        <span class="text-right text-xs tabular-nums">
                            {{ campaign.redeemed }}
                        </span>

                        <span
                            class="text-right text-xs font-bold tabular-nums"
                            :class="
                                campaign.collection_rate === null
                                    ? 'text-faint'
                                    : ''
                            "
                        >
                            {{
                                campaign.collection_rate === null
                                    ? '--'
                                    : `${campaign.collection_rate}%`
                            }}
                        </span>
                    </Link>
                </div>
            </div>
        </Card>

        <p class="text-xs text-faint">
            Campaigns are set up on
            <Link
                :href="campaignsIndex().url"
                class="font-semibold text-primary hover:underline"
                data-test="view-campaigns"
            >
                the campaigns screen</Link
            >; rewards are handed over at the redemption desk.
        </p>
    </div>
</template>
