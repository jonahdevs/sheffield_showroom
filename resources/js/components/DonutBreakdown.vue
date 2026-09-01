<script setup lang="ts">
import { computed } from 'vue';
import ApexChart from '@/components/ApexChart.vue';
import { CHART_PALETTE, useChartTheme } from '@/composables/useChartTheme';

const props = withDefaults(
    defineProps<{
        slices: App.Data.DashboardSliceData[];
        /** What sits in the hole, which is the figure the ring divides up. */
        total: number;
        label: string;
        /**
         * What the ring counts, plural and lower case. It is written into the
         * tooltip, so leaving the visits default on a ring of anything else
         * gives a hover reading "12 visits".
         */
        unit?: string;
        size?: number;
    }>(),
    { unit: 'visits', size: 210 },
);

const { theme } = useChartTheme();

const colours = computed(() =>
    props.slices.map((_, index) => CHART_PALETTE[index % CHART_PALETTE.length]),
);

const series = computed(() => props.slices.map((slice) => slice.count));

const options = computed<ApexCharts.ApexOptions>(() => ({
    chart: {
        type: 'donut',
        height: props.size,
        fontFamily: 'inherit',
        animations: { enabled: true, speed: 400 },
        toolbar: { show: false },
    },
    labels: props.slices.map((slice) => slice.label),
    colors: colours.value,
    /* The legend is drawn in the markup below instead. */
    legend: { show: false },
    dataLabels: { enabled: false },
    /* The seam is the card behind it, so the wedges separate in both themes
       without a border colour that only works in one. */
    stroke: { width: 2, colors: [theme.value.surface] },
    plotOptions: {
        pie: {
            expandOnClick: false,
            donut: { size: '74%', labels: { show: false } },
        },
    },
    tooltip: {
        theme: theme.value.isDark ? 'dark' : 'light',
        y: { formatter: (value: number) => `${value} ${props.unit}` },
    },
    states: { active: { filter: { type: 'none' } } },
}));
</script>

<template>
    <div class="flex flex-wrap items-center justify-center gap-5 p-5">
        <div class="relative shrink-0" :style="{ width: `${size}px` }">
            <ApexChart
                :options="options"
                :series="series"
                :label="props.label"
            />

            <div
                class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center"
                aria-hidden="true"
            >
                <span class="text-2xl font-extrabold tabular-nums">
                    {{ props.total }}
                </span>
                <span
                    class="text-xs font-bold tracking-[0.04em] text-faint uppercase"
                >
                    {{ props.unit }}
                </span>
            </div>
        </div>

        <ul class="flex min-w-0 flex-1 flex-col gap-2.5">
            <li
                v-for="(slice, index) in props.slices"
                :key="slice.value"
                class="flex min-w-0 items-start gap-2.5"
            >
                <span
                    class="mt-1 size-2.5 shrink-0 rounded-full"
                    :style="{ backgroundColor: colours[index] }"
                    aria-hidden="true"
                />

                <div class="min-w-0">
                    <p class="truncate text-xs font-bold" :title="slice.label">
                        {{ slice.label }}
                    </p>
                    <p class="mt-0.5 text-xs text-faint tabular-nums">
                        {{ slice.share }}% ({{ slice.count }})
                    </p>
                </div>
            </li>
        </ul>
    </div>
</template>
