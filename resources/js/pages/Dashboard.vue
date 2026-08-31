<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    ImageOff,
    Package,
    UserRound,
    UserRoundPlus,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import ApexChart from '@/components/ApexChart.vue';
import DateRangePicker from '@/components/DateRangePicker.vue';
import DonutBreakdown from '@/components/DonutBreakdown.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import PanelEmpty from '@/components/PanelEmpty.vue';
import StatTile from '@/components/StatTile.vue';
import { Card } from '@/components/ui/card';
import { CHART_PALETTE, useChartTheme } from '@/composables/useChartTheme';
import { useFilters } from '@/composables/useFilters';
import { dashboard } from '@/routes';
import { index as visitsIndex } from '@/routes/admin/visits';
import { exportMethod as dashboardExport } from '@/routes/dashboard';

const props = defineProps<{
    range: App.Data.DashboardRangeData;
    stats: App.Data.DashboardStatData[];
    trend: App.Data.DashboardTrendPointData[];
    purposes: App.Data.DashboardSliceData[];
    sources: App.Data.DashboardSliceData[];
    products: App.Data.DashboardProductInterestData[];
    respondents: App.Data.DashboardRespondentData[];
    recent: App.Data.VisitRowData[];
    /** The named windows the picker offers, shortest first. */
    presets: { value: string; label: string }[];
    /** The download formats this host can actually produce. */
    formats: string[];
    /** True for a salesperson, whose figures are their own work. */
    scoped_to_own: boolean;
    can: { view_visits: boolean };
}>();

/*
  One control drives the whole page. The mockup hangs a period picker off every
  panel; that lets the ring disagree with the line above it, and a dashboard
  whose halves answer different questions is worse than one that only answers
  one.

  `from` and `to` ride alongside the preset and stay blank unless the window was
  picked on the calendar, so a named window never carries two ways of saying the
  same thing in the URL - and the download inherits whichever it is.
*/
const { query, processing, apply } = useFilters({
    url: dashboard.url(),
    initial: {
        range: props.range.preset,
        from: props.range.preset === 'custom' ? props.range.from : '',
        to: props.range.preset === 'custom' ? props.range.to : '',
    },
    blank: { range: 'last_7_days', from: '', to: '' },
    only: [
        'range',
        'stats',
        'trend',
        'purposes',
        'sources',
        'products',
        'respondents',
        'recent',
    ],
});

/**
 * Sent at once rather than after the usual pause: a window is one decisive
 * answer, not a phrase being typed, and a beat of nothing after the popover
 * closes reads as a click that missed.
 */
function chooseWindow(from: string, to: string): void {
    apply({ range: 'custom', from, to });
}

/**
 * A window chosen by name rather than drawn.
 *
 * Clears the dates rather than leaving them behind. The server would ignore a
 * stale pair under a named window anyway, but a URL carrying
 * `range=this_month&from=2026-02-01` is one somebody will eventually read as a
 * contradiction and try to honour - and a bookmarked "this month" has to still
 * open this month next month, not the days it happened to mean when it was
 * saved.
 */
function choosePreset(range: string): void {
    apply({ range, from: '', to: '' });
}

/**
 * What the closed picker reads.
 *
 * A named window reads by its name - "This month" rather than the two dozen
 * characters of dates it resolved to - because the name is what was clicked,
 * and a button that answers a click with something the reader has to decode
 * reads as though it did something else. The calendar inside still highlights
 * the days, so the dates are one click away for anybody who wants them.
 */
const windowLabel = computed(
    () =>
        props.presets.find((preset) => preset.value === props.range.preset)
            ?.label ?? props.range.label,
);

const { theme } = useChartTheme();

// -----------------------------------------------------------------------------
// The KPI row
// -----------------------------------------------------------------------------

/**
 * The icon and wash each figure wears.
 *
 * Coloured off the same palette the donuts spend, so the row belongs to the
 * page rather than being four tiles that happen to be near it. Keyed by the
 * server's `key` so an added figure is a line here and not a rewrite.
 */
const TILES: Record<string, { icon: Component; colour: string }> = {
    visits: { icon: Users, colour: CHART_PALETTE[0] },
    customers: { icon: UserRound, colour: CHART_PALETTE[1] },
    new_customers: { icon: UserRoundPlus, colour: CHART_PALETTE[2] },
    product_interests: { icon: Package, colour: CHART_PALETTE[3] },
};

function tile(key: string) {
    return TILES[key] ?? { icon: Users, colour: CHART_PALETTE[8] };
}

// -----------------------------------------------------------------------------
// Visit trends
// -----------------------------------------------------------------------------

const visitsInRange = computed(() =>
    props.trend.reduce((total, point) => total + point.visits, 0),
);

const trendSeries = computed(() => [
    { name: 'Visits', data: props.trend.map((point) => point.visits) },
]);

const trendOptions = computed<ApexCharts.ApexOptions>(() => ({
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
    /* A wash under the line rather than a filled band: the shape is the
       reading, and a solid fill turns a quiet Tuesday into a black hole.

       Every key spelled out because Apex's defaults for an area gradient are
       wrong for a wash. Left to itself it shades the far end of the band
       towards a second colour it derives from the first, which is how a brand
       red came out fading through a muddy teal; naming the accent at both ends
       with `shade: 'light'` and no intensity leaves opacity as the only thing
       the gradient varies, which is the whole intent. The last stop lands a
       little above the floor so the wash has thinned to nothing before it
       reaches the x rule rather than laying a tint along it. */
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
        categories: props.trend.map((point) => point.label),
        /* Capped rather than one tick per day: ninety days of dates on a panel
           this wide overlap into a smear. */
        tickAmount: Math.min(props.trend.length, 7),
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
                `${value} ${value === 1 ? 'visit' : 'visits'}`,
        },
    },
}));

// -----------------------------------------------------------------------------
// Top product interests
// -----------------------------------------------------------------------------

/**
 * Bars measured against the leader rather than against the window's total.
 *
 * Five products out of a catalogue account for a small slice of everything
 * shown, so scaling to the total leaves five stubs that cannot be told apart.
 * Against the leader the differences between them are the point of the panel.
 */
const busiestProduct = computed(() =>
    Math.max(1, ...props.products.map((product) => product.visits)),
);

function barWidth(visits: number): string {
    return `${Math.max((visits / busiestProduct.value) * 100, 6)}%`;
}
</script>

<!--
  The showroom over a window: how many came, why, from where, what they were
  shown and who took them.

  Panels are measured against `@container/page` rather than the viewport,
  because behind the rail the page is a good deal narrower than the window and
  a viewport breakpoint promises room these three-across rows do not have.
-->
<template>
    <Head title="Dashboard" />

    <div class="flex flex-col gap-5">
        <div class="flex flex-wrap items-start gap-4">
            <div class="flex min-w-0 flex-1 flex-col gap-2">
                <h1 class="text-2xl leading-tight">Dashboard</h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        props.scoped_to_own
                            ? 'Your own showroom activity over'
                            : 'Showroom visits and customer activity over'
                    }}
                    {{ props.range.label }}.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <DateRangePicker
                    :from="props.range.from"
                    :to="props.range.to"
                    :label="windowLabel"
                    :presets="props.presets"
                    :active="props.range.preset"
                    data-test="dashboard-range"
                    @update="chooseWindow"
                    @preset="choosePreset"
                />

                <!-- The download follows the window, so the file is the page
                     the reader was looking at rather than the whole log. -->
                <ExportMenu
                    :url="dashboardExport().url"
                    :query="query"
                    :formats="props.formats"
                    name="dashboard"
                />
            </div>
        </div>

        <!-- Dimmed rather than skeletoned while a new window lands: the panels
             are already the right shape, and swapping seven of them for
             placeholders makes a 200ms reload look like a page load. -->
        <div
            class="flex flex-col gap-4 transition-opacity"
            :class="processing ? 'opacity-60' : ''"
            :aria-busy="processing"
        >
            <!-- Two then four: four tiles halve evenly, so there is no width
                 at which the row ends on an orphan. -->
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
                <!-- ---------------------------------------------------------
                     Visit trends
                     --------------------------------------------------------- -->
                <Card
                    as="section"
                    class="gap-0 p-0 @5xl/page:col-span-5"
                    data-test="visit-trends"
                >
                    <div
                        class="flex items-center justify-between gap-3 border-b border-divider px-5 py-3.5"
                    >
                        <h2
                            class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                        >
                            Visit trends
                        </h2>
                        <span class="text-xs text-faint tabular-nums">
                            {{ visitsInRange }} in total
                        </span>
                    </div>

                    <PanelEmpty v-if="visitsInRange === 0" :height="264">
                        No visits were logged over {{ props.range.label }}.
                    </PanelEmpty>

                    <div v-else class="p-3 pt-4">
                        <ApexChart
                            :options="trendOptions"
                            :series="trendSeries"
                            label="Visits per day over the selected period"
                        />
                    </div>
                </Card>

                <!-- ---------------------------------------------------------
                     Visits by purpose
                     --------------------------------------------------------- -->
                <Card
                    as="section"
                    class="gap-0 p-0 @5xl/page:col-span-3"
                    data-test="visits-by-purpose"
                >
                    <div class="border-b border-divider px-5 py-3.5">
                        <h2
                            class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                        >
                            Visits by purpose
                        </h2>
                    </div>

                    <PanelEmpty
                        v-if="props.purposes.length === 0"
                        :height="210"
                    >
                        Nothing to divide up yet - no visit over this period
                        recorded a purpose.
                    </PanelEmpty>

                    <DonutBreakdown
                        v-else
                        :slices="props.purposes"
                        :total="visitsInRange"
                        label="Visits divided by why the customer came in"
                    />
                </Card>

                <!-- ---------------------------------------------------------
                     Top product interests
                     --------------------------------------------------------- -->
                <Card
                    as="section"
                    class="gap-0 p-0 @5xl/page:col-span-4"
                    data-test="top-products"
                >
                    <div class="border-b border-divider px-5 py-3.5">
                        <h2
                            class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                        >
                            Top product interests
                        </h2>
                    </div>

                    <PanelEmpty
                        v-if="props.products.length === 0"
                        :height="210"
                    >
                        No product was attached to a visit over
                        {{ props.range.label }}.
                    </PanelEmpty>

                    <!-- Bars in markup rather than in the chart library: the
                         thumbnail is what a salesperson recognises the product
                         by, and a plotted bar cannot carry one beside it. -->
                    <ul v-else class="divide-y divide-divider">
                        <li
                            v-for="product in props.products"
                            :key="product.id"
                            class="flex items-center gap-3 px-5 py-3"
                        >
                            <span
                                class="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted"
                            >
                                <img
                                    v-if="product.image_url"
                                    :src="product.image_url"
                                    :alt="product.name"
                                    loading="lazy"
                                    class="size-full object-contain"
                                />
                                <ImageOff
                                    v-else
                                    class="size-4 text-faint"
                                    aria-hidden="true"
                                />
                            </span>

                            <span
                                class="w-24 shrink-0 truncate text-xs font-bold @lg/page:w-32"
                                :title="product.name"
                            >
                                {{ product.name }}
                            </span>

                            <span
                                class="h-2 min-w-8 flex-1 overflow-hidden rounded-full bg-muted"
                            >
                                <span
                                    class="block h-full rounded-full bg-primary"
                                    :style="{ width: barWidth(product.visits) }"
                                />
                            </span>

                            <span
                                class="w-7 shrink-0 text-right text-xs font-bold tabular-nums"
                            >
                                {{ product.visits }}
                            </span>
                        </li>
                    </ul>
                </Card>
            </div>

            <div
                class="grid gap-4 @2xl/page:grid-cols-2 @5xl/page:grid-cols-12"
            >
                <!-- ---------------------------------------------------------
                     Visits by source
                     --------------------------------------------------------- -->
                <Card
                    as="section"
                    class="gap-0 p-0 @5xl/page:col-span-3"
                    data-test="visits-by-source"
                >
                    <div class="border-b border-divider px-5 py-3.5">
                        <h2
                            class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                        >
                            Visits by source
                        </h2>
                    </div>

                    <PanelEmpty v-if="props.sources.length === 0" :height="210">
                        Nothing to divide up yet - no visit over this period
                        recorded a source.
                    </PanelEmpty>

                    <DonutBreakdown
                        v-else
                        :slices="props.sources"
                        :total="visitsInRange"
                        label="Visits divided by how the customer found the showroom"
                    />
                </Card>

                <!-- ---------------------------------------------------------
                     Respondent performance
                     --------------------------------------------------------- -->
                <Card
                    as="section"
                    class="gap-0 p-0 @5xl/page:col-span-4"
                    data-test="respondent-performance"
                >
                    <div class="border-b border-divider px-5 py-3.5">
                        <h2
                            class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                        >
                            Respondent performance
                        </h2>
                    </div>

                    <PanelEmpty
                        v-if="props.respondents.length === 0"
                        :height="210"
                    >
                        Nobody took a visit over {{ props.range.label }}.
                    </PanelEmpty>

                    <div v-else class="overflow-x-auto">
                        <!-- Semibold rather than bold: the lead cell of every row below
                             is itself text-xs font-bold, so a bold header would read as
                             one more row instead of the label for all of them. -->
                        <div
                            class="grid min-w-[380px] grid-cols-[minmax(0,1fr)_60px_84px_84px] items-center gap-3 border-b border-divider bg-muted/50 px-5 py-3 text-xs font-semibold"
                        >
                            <span>Respondent</span>
                            <span class="text-right">Visits</span>
                            <span class="text-right">Customers</span>
                            <span class="text-right">Follow-ups</span>
                        </div>

                        <div class="divide-y divide-divider">
                            <div
                                v-for="person in props.respondents"
                                :key="person.name"
                                class="grid min-w-[380px] grid-cols-[minmax(0,1fr)_60px_84px_84px] items-center gap-3 px-5 py-3"
                            >
                                <!-- No avatar beside the name: `respondent` is
                                     free text on the visit, not a user, so the
                                     initials could stand for somebody who never
                                     had an account and a face to go with them. -->
                                <span
                                    class="truncate text-xs font-bold"
                                    :title="person.name"
                                >
                                    {{ person.name }}
                                </span>

                                <span
                                    class="text-right text-xs font-bold tabular-nums"
                                >
                                    {{ person.visits }}
                                </span>
                                <span
                                    class="text-right text-xs tabular-nums"
                                    :class="
                                        person.customers > 0 ? '' : 'text-faint'
                                    "
                                >
                                    {{ person.customers }}
                                </span>
                                <span
                                    class="text-right text-xs tabular-nums"
                                    :class="
                                        person.follow_ups > 0
                                            ? ''
                                            : 'text-faint'
                                    "
                                >
                                    {{ person.follow_ups }}
                                </span>
                            </div>
                        </div>
                    </div>
                </Card>

                <!-- ---------------------------------------------------------
                     Recent visits
                     --------------------------------------------------------- -->
                <Card
                    as="section"
                    class="gap-0 p-0 @5xl/page:col-span-5"
                    data-test="recent-visits"
                >
                    <div
                        class="flex items-center justify-between gap-3 border-b border-divider px-5 py-3.5"
                    >
                        <h2
                            class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                        >
                            Recent visits
                        </h2>

                        <Link
                            v-if="props.can.view_visits"
                            :href="visitsIndex().url"
                            class="flex items-center gap-1 text-xs font-bold text-primary hover:underline"
                            data-test="view-all-visits"
                        >
                            View all
                            <ArrowRight class="size-3.5" />
                        </Link>
                    </div>

                    <PanelEmpty v-if="props.recent.length === 0" :height="210">
                        No visits were logged over {{ props.range.label }}.
                    </PanelEmpty>

                    <div v-else class="overflow-x-auto">
                        <!-- Semibold rather than bold: the lead cell of every row below
                             is itself text-xs font-bold, so a bold header would read as
                             one more row instead of the label for all of them. -->
                        <div
                            class="grid min-w-[500px] grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_44px_minmax(0,0.9fr)_76px] items-center gap-3 border-b border-divider bg-muted/50 px-5 py-3 text-xs font-semibold"
                        >
                            <span>Customer</span>
                            <span>Purpose</span>
                            <span class="text-right">Items</span>
                            <span>Respondent</span>
                            <span class="text-right">When</span>
                        </div>

                        <div class="divide-y divide-divider">
                            <div
                                v-for="visit in props.recent"
                                :key="visit.id"
                                class="grid min-w-[500px] grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_44px_minmax(0,0.9fr)_76px] items-center gap-3 px-5 py-3"
                            >
                                <span
                                    class="truncate text-xs font-bold"
                                    :title="visit.customer_name"
                                >
                                    {{ visit.customer_name }}
                                </span>

                                <span
                                    class="truncate text-xs"
                                    :title="visit.purpose_label"
                                >
                                    {{ visit.purpose_label }}
                                </span>

                                <!-- Counted here where the list names them:
                                     the panel is scanned for who came, and
                                     five product names a row would push
                                     everything else off the card. -->
                                <span
                                    class="text-right text-xs tabular-nums"
                                    :class="
                                        visit.products.length > 0
                                            ? ''
                                            : 'text-faint'
                                    "
                                    :title="
                                        visit.products.length > 0
                                            ? visit.products.join(', ')
                                            : undefined
                                    "
                                >
                                    {{ visit.products.length }}
                                </span>

                                <span class="truncate text-xs text-faint">
                                    {{ visit.attended_by ?? '--' }}
                                </span>

                                <div class="text-right text-xs">
                                    <p class="truncate tabular-nums">
                                        {{ visit.visited_time }}
                                    </p>
                                    <p
                                        class="mt-0.5 truncate text-faint tabular-nums"
                                    >
                                        {{ visit.visited_on }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </Card>
            </div>
        </div>
    </div>
</template>
