<script setup lang="ts">
import { Minus, TrendingDown, TrendingUp } from '@lucide/vue';
import type { Component } from 'vue';
import { Card } from '@/components/ui/card';

const props = defineProps<{
    stat: App.Data.DashboardStatData;
    icon: Component;
    /** The tile's own colour. The glyph takes it; the pad takes a wash of it. */
    colour: string;
    /** What the delta is measured against, e.g. "vs yesterday". */
    comparison: string;
}>();

/** A wash of the tile's own colour, thin enough to sit on either theme. */
function wash(colour: string): string {
    return `color-mix(in oklab, ${colour} 14%, transparent)`;
}

function movement(change: number): string {
    const rounded = Math.abs(change);

    return `${change > 0 ? '+' : change < 0 ? '-' : ''}${rounded}%`;
}

function movementIcon(change: number): Component {
    return change > 0 ? TrendingUp : change < 0 ? TrendingDown : Minus;
}

/**
 * The colour a delta is drawn in.
 *
 * Every figure this tile is used for is one where more is better, so the
 * arrow's direction and the reading of it agree and one function can serve
 * both. A metric that counted problems - overdue follow-ups, say - would break
 * that agreement: the arrow would still follow the direction while the colour
 * followed whether that direction is welcome. That wants a flag on the stat
 * itself rather than a special case here, so it is worth knowing the
 * distinction exists before the first such figure is added.
 */
function movementTone(change: number): string {
    if (change > 0) {
        return 'text-emerald-600 dark:text-emerald-400';
    }

    return change < 0 ? 'text-destructive' : 'text-faint';
}
</script>

<!--
  One figure in a KPI row: a washed glyph beside a label, the number, and how
  it moved.

  Shared by the dashboard and the visits list rather than copied into each,
  because the two rows sit two clicks apart and a reader moving between them
  reads them as the same instrument. Copies drift; this cannot.
-->
<template>
    <Card
        class="flex-row items-start gap-3.5"
        :data-test="`stat-${props.stat.key}`"
    >
        <span
            class="flex size-11 shrink-0 items-center justify-center rounded-xl"
            :style="{
                backgroundColor: wash(props.colour),
                color: props.colour,
            }"
            aria-hidden="true"
        >
            <component :is="props.icon" class="size-5" />
        </span>

        <div class="min-w-0 flex-1">
            <!-- Sentence case rather than small caps: a label is read once and
                 the figure under it is what the eye is here for. -->
            <p
                class="truncate text-xs font-medium text-muted-foreground"
                :title="props.stat.label"
            >
                {{ props.stat.label }}
            </p>

            <!-- Tight tracking because a figure set at this weight opens up on
                 its own, and tabular figures because a whole row of them is
                 redrawn at once - proportional digits make it twitch. -->
            <p
                class="mt-0.5 truncate text-xl leading-tight font-bold tracking-[-0.02em] tabular-nums"
            >
                {{ props.stat.value.toLocaleString() }}
            </p>

            <p
                v-if="props.stat.change === null"
                class="mt-1.5 truncate text-xs font-medium text-faint"
            >
                Nothing before it to compare
            </p>

            <p v-else class="mt-1.5 flex items-center gap-1.5 truncate text-xs">
                <span
                    class="flex items-center gap-0.5 font-semibold tabular-nums"
                    :class="movementTone(props.stat.change)"
                >
                    <component
                        :is="movementIcon(props.stat.change)"
                        class="size-3.5 shrink-0"
                        :stroke-width="2.2"
                        aria-hidden="true"
                    />
                    {{ movement(props.stat.change) }}
                </span>
                <span class="truncate text-faint">{{ props.comparison }}</span>
            </p>
        </div>
    </Card>
</template>
