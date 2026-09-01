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
        from: string;
        to: string;
        label: string;
        /* Named windows. A preset emits its value for the page to put in the
           query string; resolving "this month" here would resolve it in the
           browser's timezone rather than the showroom's. */
        presets?: { value: string; label: string }[];
        active?: string;
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
        /* A hand-typed query string can carry anything. */
        return undefined;
    }
}

/** The calendar's own two ends, including a range only half-picked. */
const draft = shallowRef<DateRange>({
    start: toDateValue(props.from),
    end: toDateValue(props.to),
});

/* The server normalises the window - a reversed pair comes back swapped, a
   future end comes back clipped - so the calendar is redrawn from its answer
   rather than from the click. */
watch(
    () => [props.from, props.to],
    ([from, to]) => {
        draft.value = { start: toDateValue(from), end: toDateValue(to) };
    },
);

const maxValue = computed(() => today(getLocalTimeZone()));

/* The calendar's default spans a century either side - unaimable. */
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

/* The draft is deliberately left alone: the watcher above redraws it from the
   days the server resolved the name to, and blanking it here would flash an
   empty calendar for the length of the request. */
function choosePreset(value: string, close: () => void): void {
    emit('preset', value);
    close();
}
</script>

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
