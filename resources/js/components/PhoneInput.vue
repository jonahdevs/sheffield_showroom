<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { DIAL_CODES, flagUrl, splitDial } from '@/lib/dialCodes';

const model = defineModel<string>({ required: true });

const props = withDefaults(
    defineProps<{
        id?: string;
        placeholder?: string;
        dataTest?: string;
    }>(),
    {
        id: undefined,
        placeholder: '712 345 678',
        dataTest: 'phone',
    },
);

/*
 * The two halves are held separately because that is how they are edited, and
 * joined on the way out because a phone number is one string everywhere else -
 * the column, the DTO and whatever eventually dials it.
 */
const initial = splitDial(model.value);

const dial = ref(initial.dial);
const number = ref(initial.number);
const open = ref(false);

const current = computed(
    () => DIAL_CODES.find((code) => code.dial === dial.value) ?? DIAL_CODES[0],
);

/*
 * An empty box is an absent number, not a bare country code: a row holding
 * `+254` describes nobody.
 *
 * The leading zero goes because it is a trunk prefix, not part of the number:
 * `0722 000 111` dialled from abroad is `+254 722 000 111`, and keeping both
 * would store a number nothing can call.
 */
const joined = computed(() => {
    /* Separators come off too. The person typing groups the digits to read
       them back; a stored `+254722 000 111` is not a number anything can
       dial, and it is not what the same number typed without spaces would
       have produced. */
    const national = number.value.replace(/[^\d]/g, '').replace(/^0+/, '');

    return national === '' ? '' : `${dial.value}${national}`;
});

watch(joined, (value) => (model.value = value));

/**
 * A number set from outside - a form reset, or a record arriving late - is read
 * back apart. Guarded on the joined value so the watcher above cannot feed its
 * own write back in and fight the person typing.
 */
watch(model, (value) => {
    if (value === joined.value) {
        return;
    }

    const parsed = splitDial(value);

    dial.value = parsed.dial;
    number.value = parsed.number;
});

function choose(value: string) {
    dial.value = value;
    open.value = false;
}
</script>

<!--
  A phone number: the country's flag and dial code on the left, the rest of the
  number beside it. One control over two fields, so a caller binds a single
  string and never has to remember which half it kept.

  Eighty-seven countries is too many to scroll, so the code is picked from a
  searchable list - by name, by ISO code or by the dialling code itself.

  The flag is an image rather than the emoji such a list usually carries:
  Windows ships no flag glyphs, so the emoji renders there as two bare letters.
-->
<template>
    <InputGroup>
        <InputGroupAddon class="pr-0">
            <Popover v-model:open="open">
                <PopoverTrigger as-child>
                    <InputGroupButton
                        type="button"
                        size="sm"
                        role="combobox"
                        class="w-22 justify-start gap-1.5 font-normal"
                        aria-label="Country dialling code"
                        :data-test="`${props.dataTest}-dial`"
                    >
                        <img
                            :src="flagUrl(current.iso)"
                            :alt="current.name"
                            class="h-3.5 w-5 shrink-0 rounded-xs object-cover"
                        />
                        <span class="tabular-nums">{{ current.dial }}</span>
                        <ChevronDown
                            class="ml-auto size-4 shrink-0 text-muted-foreground"
                            :stroke-width="2"
                        />
                    </InputGroupButton>
                </PopoverTrigger>

                <PopoverContent class="w-72 p-0" align="start">
                    <Command>
                        <CommandInput placeholder="Search country or code" />
                        <CommandEmpty>No country matches.</CommandEmpty>
                        <CommandList>
                            <CommandGroup>
                                <CommandItem
                                    v-for="code in DIAL_CODES"
                                    :key="code.iso"
                                    :value="`${code.name} ${code.iso} ${code.dial}`"
                                    class="gap-2"
                                    @select="choose(code.dial)"
                                >
                                    <img
                                        :src="flagUrl(code.iso)"
                                        :alt="code.name"
                                        class="h-3.5 w-5 shrink-0 rounded-xs object-cover"
                                    />
                                    <span class="min-w-0 flex-1 truncate">
                                        {{ code.name }}
                                    </span>
                                    <span class="text-faint tabular-nums">
                                        {{ code.dial }}
                                    </span>
                                </CommandItem>
                            </CommandGroup>
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>
        </InputGroupAddon>

        <InputGroupInput
            :id="props.id"
            v-model="number"
            type="tel"
            inputmode="tel"
            autocomplete="tel-national"
            :placeholder="props.placeholder"
            :data-test="props.dataTest"
        />
    </InputGroup>
</template>
