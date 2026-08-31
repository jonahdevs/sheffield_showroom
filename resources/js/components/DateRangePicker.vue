<script setup lang="ts">
import { getLocalTimeZone, parseDate, today } from '@internationalized/date';
import { CalendarIcon } from '@lucide/vue';
import type { DateRange, DateValue } from 'reka-ui';
import { createYearRange } from 'reka-ui/date';
import { computed, shallowRef, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { RangeCalendar } from '@/components/ui/range-calendar';

const props = withDefaults(
    defineProps<{
        /** The window's near end, as an ISO date. */
        from: string;
        /** The window's far end, as an ISO date. */
        to: string;
        /** The window written out, which is what the closed button reads. */
        label: string;
        /**
         * The windows that have a name, offered as one click each beside the
         * calendar. Empty - the default - and the popover is the calendar
         * alone, which is how the dashboard uses this control.
         *
         * The names are the server's, not this component's: a preset emits its
         * value and the page puts that in the query string, so the window is
         * resolved into dates in one place. Resolving "this month" here would
         * mean a second implementation of every window, in the browser's
         * timezone rather than the showroom's, drifting from the first the day
         * either is corrected.
         */
        presets?: { value: string; label: string }[];
        /** Which preset the page is currently reading under, if any. */
        active?: string;
        /**
         * How far back the year dropdown reaches. A showroom log only ever
         * looks behind itself, so five years unless a page says otherwise.
         */
        yearsBack?: number;
        align?: 'start' | 'end';
        dataTest?: string;
    }>(),
    {
        presets: () => [],
        active: '',
        yearsBack: 5,
        align: 'end',
        dataTest: 'date-range',
    },
);

const emit = defineEmits<{
    (event: 'update', from: string, to: string): void;
    (event: 'preset', value: string): void;
}>();

function toDateValue(value: string): DateValue | undefined {
    if (value === '') {
        return undefined;
    }

    try {
        return parseDate(value);
    } catch {
        /* A hand-typed query string can carry anything, and a window that
           will not parse reads as unset rather than throwing the page away. */
        return undefined;
    }
}

/**
 * The calendar's own two ends.
 *
 * A first click leaves a range half-picked, which nothing outside is told
 * about yet - the draft is what the calendar draws until the second click
 * makes a window worth asking the server for.
 */
const draft = shallowRef<DateRange>({
    start: toDateValue(props.from),
    end: toDateValue(props.to),
});

/* The server resolves the window and hands back what it settled on - a pair
   the wrong way round comes back swapped, an end in the future comes back
   clipped - so the calendar is redrawn from the answer rather than from the
   click. */
watch(
    () => [props.from, props.to],
    ([from, to]) => {
        draft.value = { start: toDateValue(from), end: toDateValue(to) };
    },
);

const maxValue = computed(() => today(getLocalTimeZone()));

/* Only the years a visit could have been logged in. The calendar's own default
   spans a century either side, which is a dropdown nobody can aim at. */
const yearRange = computed(() =>
    createYearRange({
        start: maxValue.value.cycle('year', -props.yearsBack),
        end: maxValue.value,
    }),
);

function pick(range: DateRange | undefined): void {
    draft.value = { start: range?.start, end: range?.end };

    if (range?.start === undefined || range?.end === undefined) {
        return;
    }

    emit('update', range.start.toString(), range.end.toString());
}

/**
 * A named window, which closes the popover the way a second calendar click
 * does.
 *
 * The draft is left alone rather than cleared: the server answers with the
 * days the name resolved to, the watcher above redraws the calendar from them,
 * and blanking it here would flash an empty calendar for the length of the
 * request.
 */
function choosePreset(value: string, close: () => void): void {
    emit('preset', value);
    close();
}
</script>

<!--
  A window, picked rather than typed - the two-ended counterpart of
  `DatePicker`, and built the same way: an outline button that prints what the
  window resolved to, and one calendar in a popover under it. `month-and-year`
  is the same layout that control uses, so the month and the year are the
  page's own native selects rather than a second popover opening inside this
  one.

  Where a page hands it `presets`, a rail of named windows opens beside the
  calendar: a log is far more often read by "this month" than by two dates
  somebody had to find, and making the common answer two clicks of a calendar
  is how a filter ends up unused.

  It holds no filter state and goes nowhere on its own - the page that owns the
  window decides what a new one means, and whether a name or a pair of dates
  ends up in the query string.
-->
<template>
    <Popover v-slot="{ close }">
        <PopoverTrigger as-child>
            <Button
                variant="outline"
                class="gap-2 font-normal"
                :data-test="props.dataTest"
                aria-label="Period the page is measured over"
            >
                <CalendarIcon class="size-4 text-faint" />
                {{ props.label }}
            </Button>
        </PopoverTrigger>

        <PopoverContent class="w-auto p-0" :align="props.align">
            <!-- The rail sits above the calendar on a narrow screen and beside
                 it on a wide one. Beside is the better reading - the names and
                 the days they resolve to are one glance apart - but a phone has
                 no room for a column of buttons and a calendar side by side,
                 and a popover wider than the screen is worse than a scroll. -->
            <div class="flex flex-col sm:flex-row">
                <div
                    v-if="props.presets.length > 0"
                    class="flex gap-1 overflow-x-auto border-b border-divider p-2 sm:flex-col sm:overflow-x-visible sm:border-r sm:border-b-0"
                >
                    <Button
                        v-for="preset in props.presets"
                        :key="preset.value"
                        :variant="
                            preset.value === props.active
                                ? 'secondary'
                                : 'ghost'
                        "
                        size="sm"
                        class="justify-start whitespace-nowrap"
                        :aria-pressed="preset.value === props.active"
                        :data-test="`${props.dataTest}-${preset.value}`"
                        @click="choosePreset(preset.value, close)"
                    >
                        {{ preset.label }}
                    </Button>
                </div>

                <RangeCalendar
                    :model-value="draft"
                    :max-value="maxValue"
                    :year-range="yearRange"
                    layout="month-and-year"
                    initial-focus
                    @update:model-value="
                        (range) => {
                            pick(range);
                            range?.end !== undefined && close();
                        }
                    "
                />
            </div>
        </PopoverContent>
    </Popover>
</template>
