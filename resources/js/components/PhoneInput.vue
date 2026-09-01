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

const initial = splitDial(model.value);

const dial = ref(initial.dial);
const number = ref(initial.number);
const open = ref(false);

const current = computed(
    () => DIAL_CODES.find((code) => code.dial === dial.value) ?? DIAL_CODES[0],
);

/* An empty box is an absent number, not a bare country code. The leading zero
   is a trunk prefix rather than part of the number - `0722 000 111` dialled
   from abroad is `+254 722 000 111` - and the grouping separators are the
   typist's, so both come off before storing. */
const joined = computed(() => {
    const national = number.value.replace(/[^\d]/g, '').replace(/^0+/, '');

    return national === '' ? '' : `${dial.value}${national}`;
});

watch(joined, (value) => (model.value = value));

/* A number set from outside is read back apart. Guarded on the joined value so
   the watcher above cannot feed its own write back in and fight the typist. */
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

<!-- The flags are images rather than emoji: Windows ships no flag glyphs, so
     the emoji renders there as two bare letters. -->
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
