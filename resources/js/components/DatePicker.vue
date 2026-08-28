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
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

/** The ISO string the server stores, which is what a caller binds. */
const model = defineModel<string>({ required: true });

const props = withDefaults(
    defineProps<{
        id?: string;
        placeholder?: string;
        dataTest?: string;
        /** Latest date the calendar will offer. 'today' for a birth date. */
        max?: 'today' | undefined;
    }>(),
    {
        id: undefined,
        placeholder: 'Pick a date',
        dataTest: undefined,
        max: undefined,
    },
);

const formatter = new DateFormatter('en-GB', { dateStyle: 'long' });

const maxValue = computed(() =>
    props.max === 'today' ? today(getLocalTimeZone()) : undefined,
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
</script>

<!--
  A date, picked rather than typed. The month-and-year layout is what makes a
  birth date bearable: without it, reaching 1985 is forty clicks.
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
                {{
                    value === undefined
                        ? props.placeholder
                        : formatter.format(value.toDate(getLocalTimeZone()))
                }}
            </Button>
        </PopoverTrigger>

        <PopoverContent class="w-auto p-0" align="start">
            <Calendar
                v-model="value"
                :max-value="maxValue"
                layout="month-and-year"
                initial-focus
                @update:model-value="close"
            />
        </PopoverContent>
    </Popover>
</template>
