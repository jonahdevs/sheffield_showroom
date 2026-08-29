<script setup lang="ts">
import {
    DateFormatter,
    getLocalTimeZone,
    parseDate,
    today,
} from '@internationalized/date';
import { CalendarIcon } from '@lucide/vue';
import type { DateValue } from 'reka-ui';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

/** The ISO string the server stores, which is what a caller binds. */
const model = defineModel<string>({ required: true });

/**
 * The clock half, `HH:mm`, for the callers that want one.
 *
 * Kept as its own model rather than folded into the date string: the two are
 * separate columns and separate rules on the way in, and an error against
 * either has to have somewhere to land.
 */
const time = defineModel<string>('time', { default: '' });

const props = withDefaults(
    defineProps<{
        id?: string;
        placeholder?: string;
        dataTest?: string;
        /** Latest date the calendar will offer. 'today' for a birth date. */
        max?: 'today' | undefined;
        /**
         * Whether the clock is asked for alongside the calendar.
         *
         * A visit happened at a moment rather than on a date and separately at
         * a time, so it is one control - but a follow-up is a day, and giving
         * it a clock would ask for a precision nobody has.
         */
        withTime?: boolean;
    }>(),
    {
        id: undefined,
        placeholder: 'Pick a date',
        dataTest: undefined,
        max: undefined,
        withTime: false,
    },
);

const formatter = new DateFormatter('en-GB', { dateStyle: 'long' });

const maxValue = computed(() =>
    props.max === 'today' ? today(getLocalTimeZone()) : undefined,
);

const timeId = computed(() =>
    props.id === undefined ? undefined : `${props.id}_time`,
);

/*
 * `Calendar` speaks `DateValue` and the field is an ISO string, so the model is
 * proxied. A value that will not parse reads as empty rather than throwing -
 * the server is what rejects it, and a form should not blank out around it.
 */
const value = computed<DateValue | undefined>({
    get: () => {
        if (model.value === '') {
            return undefined;
        }

        try {
            return parseDate(model.value);
        } catch {
            return undefined;
        }
    },
    set: (next) => {
        model.value = next === undefined ? '' : next.toString();
    },
});

/** What the closed button reads. */
const label = computed(() => {
    if (value.value === undefined) {
        return props.placeholder;
    }

    const date = formatter.format(value.value.toDate(getLocalTimeZone()));

    return props.withTime && time.value !== ''
        ? `${date} at ${time.value}`
        : date;
});

/**
 * Picking a date closes the popover - unless there is a clock under it, in
 * which case closing on the first of the two answers puts the second out of
 * reach.
 */
function onDatePicked(close: () => void) {
    if (!props.withTime) {
        close();
    }
}
</script>

<!--
  A date, picked rather than typed. The month-and-year layout is what makes a
  birth date bearable: without it, reaching 1985 is forty clicks.

  With `with-time` the clock sits under the calendar in the same popover, so a
  moment is one control and one answer rather than two fields that happen to
  be next to each other.
-->
<template>
    <Popover v-slot="{ close }">
        <PopoverTrigger as-child>
            <Button
                :id="props.id"
                type="button"
                variant="outline"
                class="w-full justify-start font-normal"
                :class="value === undefined ? 'text-muted-foreground' : ''"
                :data-test="props.dataTest"
            >
                <CalendarIcon class="size-4" />
                {{ label }}
            </Button>
        </PopoverTrigger>

        <PopoverContent class="w-auto p-0" align="start">
            <Calendar
                v-model="value"
                :max-value="maxValue"
                layout="month-and-year"
                initial-focus
                @update:model-value="onDatePicked(close)"
            />

            <div
                v-if="props.withTime"
                class="flex items-center gap-3 border-t p-3"
            >
                <Label :for="timeId" class="shrink-0 text-xs">Time</Label>
                <Input
                    :id="timeId"
                    v-model="time"
                    type="time"
                    class="h-9 py-1.5"
                    :data-test="
                        props.dataTest ? `${props.dataTest}-time` : undefined
                    "
                />
                <Button type="button" size="sm" variant="quiet" @click="close">
                    Done
                </Button>
            </div>
        </PopoverContent>
    </Popover>
</template>
