<script setup lang="ts">
import ApexCharts from 'apexcharts';
import { onMounted, onUnmounted, useTemplateRef, watch } from 'vue';

const props = defineProps<{
    options: ApexCharts.ApexOptions;
    series: ApexCharts.ApexAxisChartSeries | ApexCharts.ApexNonAxisChartSeries;
    /** What the chart is, for anybody who cannot see it. */
    label: string;
}>();

const host = useTemplateRef<HTMLDivElement>('host');

/* Not a `ref`: ApexCharts owns its own DOM, so a reactive proxy only makes
   Vue walk a large self-mutating object for no benefit. */
let chart: ApexCharts | null = null;

onMounted(async () => {
    if (host.value === null) {
        return;
    }

    chart = new ApexCharts(host.value, {
        ...props.options,
        series: props.series,
    });

    await chart.render();
});

/* One watcher, options first: updating the series first leaves the chart a
   beat with more slices than it has labels for. */
watch(
    [() => props.options, () => props.series],
    async () => {
        if (chart === null) {
            return;
        }

        /* Redraw paths, never animate: across a change of window the points
           are a different set of days, and tweening between them draws a
           movement that never happened. */
        await chart.updateOptions(props.options, true, false);
        await chart.updateSeries(props.series, false);
    },
    { deep: true },
);

/* ApexCharts hangs a resize observer and document listeners off every
   instance, and Inertia never reloads: without this it leaks per navigation. */
onUnmounted(() => {
    chart?.destroy();
    chart = null;
});
</script>

<template>
    <div ref="host" role="img" :aria-label="props.label" class="w-full" />
</template>
