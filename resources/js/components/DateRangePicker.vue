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
         * How far back the year dropdown reaches. A showroom log only ever
         * looks behind itself, so five years unless a page says otherwise.
         */
        yearsBack?: number;
        align?: 'start' | 'end';
        dataTest?: string;
    }>(),
    { yearsBack: 5, align: 'end', dataTest: 'date-range' },
);

const emit = defineEmits<{
    (event: 'update', from: string, to: string): void;
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
</script>

<!--
  A window, picked rather than typed - the two-ended counterpart of
  `DatePicker`, and built the same way: an outline button that prints what the
  window resolved to, and one calendar in a popover under it. `month-and-year`
  is the same layout that control uses, so the month and the year are the
  page's own native selects rather than a second popover opening inside this
  one.

  It holds no filter state and goes nowhere on its own - the page that owns the
  window decides what a new one means.
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
        </PopoverContent>
    </Popover>
</template>
