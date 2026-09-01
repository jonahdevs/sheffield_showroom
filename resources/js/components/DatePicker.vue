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

const model = defineModel<string>({ required: true });

/** The clock half, `HH:mm`. Its own model: separate column, separate rule. */
const time = defineModel<string>('time', { default: '' });

const props = withDefaults(
    defineProps<{
        id?: string;
        placeholder?: string;
        dataTest?: string;
        /** Latest date the calendar will offer. 'today' for a birth date. */
        max?: 'today' | undefined;
        withTime?: boolean;
        /* Read-only, for a record opened uneditable - a published reward
           campaign, say. Disables the trigger rather than the calendar, so the
           button still prints the date it holds. */
        disabled?: boolean;
    }>(),
    {
        id: undefined,
        placeholder: 'Pick a date',
        dataTest: undefined,
        max: undefined,
        withTime: false,
        disabled: false,
    },
);

const formatter = new DateFormatter('en-GB', { dateStyle: 'long' });

const maxValue = computed(() =>
    props.max === 'today' ? today(getLocalTimeZone()) : undefined,
);

const timeId = computed(() =>
    props.id === undefined ? undefined : `${props.id}_time`,
);

/* `Calendar` speaks `DateValue` and the field is an ISO string, so the model
   is proxied. Anything that will not parse reads as empty rather than
   throwing: the server is what rejects it. */
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

const label = computed(() => {
    if (value.value === undefined) {
        return props.placeholder;
    }

    const date = formatter.format(value.value.toDate(getLocalTimeZone()));

    return props.withTime && time.value !== ''
        ? `${date} at ${time.value}`
        : date;
});

/* Picking a date closes the popover - unless there is a clock under it, where
   closing on the first answer would put the second out of reach. */
function onDatePicked(close: () => void) {
    if (!props.withTime) {
        close();
    }
}
</script>

<template>
    <Popover v-slot="{ close }">
        <PopoverTrigger as-child>
            <Button
                :id="props.id"
                type="button"
                variant="outline"
                class="w-full justify-start font-normal"
                :class="value === undefined ? 'text-muted-foreground' : ''"
                :disabled="props.disabled"
                :data-test="props.dataTest"
            >
                <CalendarIcon class="size-4" />
                {{ label }}
            </Button>
        </PopoverTrigger>

        <PopoverContent class="w-auto p-0" align="start">
            <!-- `month-and-year` is what makes a birth date bearable: without
                 it, reaching 1985 is forty clicks. -->
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
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    @click="close"
                >
                    Done
                </Button>
            </div>
        </PopoverContent>
    </Popover>
</template>
