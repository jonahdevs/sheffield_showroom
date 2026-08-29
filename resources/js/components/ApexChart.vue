<script setup lang="ts">
import ApexCharts from 'apexcharts';
import { onMounted, onUnmounted, useTemplateRef, watch } from 'vue';

const props = defineProps<{
    options: ApexCharts.ApexOptions;
    series: ApexCharts.ApexAxisChartSeries | ApexCharts.ApexNonAxisChartSeries;
    /**
     * What the chart is, for anybody who cannot see it. A canvas of paths says
     * nothing on its own, and the panel around it names the section rather
     * than the figures.
     */
    label: string;
}>();

const host = useTemplateRef<HTMLDivElement>('host');

/*
  A plain variable rather than a `ref`: ApexCharts is a large, self-mutating
  object that owns its own DOM, and wrapping it in a proxy invites Vue to walk
  it on every change for no benefit.
*/
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

/*
  Options before series, in one watcher rather than two. The two move together
  when the window changes - a donut gains a wedge and the label and colour for
  it in the same breath - and updating the series first would leave the chart a
  beat where it has more slices than it has names for them.
*/
watch(
    [() => props.options, () => props.series],
    async () => {
        if (chart === null) {
            return;
        }

        /* Redrawn rather than animated from the previous paths: across a
           change of window the points are a different set of days, and tweening
           between them draws a movement that never happened. */
        await chart.updateOptions(props.options, true, false);
        await chart.updateSeries(props.series, false);
    },
    { deep: true },
);

/*
  ApexCharts hangs a resize observer and document-level listeners off every
  instance it builds. Inertia swaps pages without a reload, so a chart that is
  not torn down here is a leak that survives every navigation for the life of
  the tab.
*/
onUnmounted(() => {
    chart?.destroy();
    chart = null;
});
</script>

<!--
  ApexCharts, driven directly. There is no Vue wrapper in this project, and the
  library is imperative by design: it takes a real element, builds its own SVG
  into it, and keeps the sizing in step with the container itself - so the job
  here is a lifetime and a pair of updates, not a re-render.
-->
<template>
    <div ref="host" role="img" :aria-label="props.label" class="w-full" />
</template>
