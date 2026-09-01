<script setup lang="ts" generic="T extends App.Data.OptionData">
import { Check, ChevronsUpDown, UserPlus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Combobox,
    ComboboxAnchor,
    ComboboxGroup,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
    ComboboxTrigger,
    ComboboxViewport,
} from '@/components/ui/combobox';
import { countMatches, matchOptions } from '@/lib/options';

const props = withDefaults(
    defineProps<{
        options: T[];
        /** The chosen record's id, or null while what is named is somebody new. */
        modelValue: number | null;
        name: string;
        id?: string;
        placeholder?: string;
        searchPlaceholder?: string;
        newLabel?: string;
        dataTest?: string;
    }>(),
    {
        id: undefined,
        placeholder: 'Search or type a name',
        searchPlaceholder: 'Type to search',
        newLabel: 'New customer',
        dataTest: undefined,
    },
);

const emit = defineEmits<{
    pick: [option: T];
    create: [name: string];
}>();

const open = ref(false);
const search = ref('');

const selected = computed(
    () =>
        props.options.find((option) => option.value === props.modelValue) ??
        null,
);

/* `ignore-filter` on the root, and the narrowing done here instead: reka's own
   filter keeps every item mounted and merely hides the misses. */
const matches = computed(() => matchOptions(props.options, search.value));

const hiddenCount = computed(
    () => countMatches(props.options, search.value) - matches.value.length,
);

const isNew = computed(() => props.modelValue === null && props.name !== '');

/** A string among numeric ids, so the last row cannot collide with a record. */
const NEW_ROW = '__new__';

function onSelect(chosen: unknown) {
    open.value = false;

    if (chosen === NEW_ROW) {
        emit('create', search.value.trim());

        return;
    }

    const option = props.options.find(
        (candidate) => candidate.value === chosen,
    );

    if (option !== undefined) {
        emit('pick', option);
    }
}

/* Reopening seeds the search with a typed-in name, and only a typed-in one:
   that is what makes a typo fixable, whereas a name that came off the list is
   being searched past to reach a different record. */
watch(open, (isOpen) => {
    if (!isOpen) {
        search.value = '';

        return;
    }

    search.value = isNew.value ? props.name : '';
});
</script>

<template>
    <Combobox
        :model-value="props.modelValue"
        v-model:open="open"
        ignore-filter
        class="w-full"
        @update:model-value="onSelect"
    >
        <ComboboxAnchor as-child class="w-full">
            <ComboboxTrigger as-child>
                <Button
                    :id="props.id"
                    type="button"
                    variant="outline"
                    class="w-full justify-start px-3 font-normal"
                    :data-test="props.dataTest"
                >
                    <span
                        class="min-w-0 flex-1 truncate text-left"
                        :class="
                            props.name === '' ? 'text-muted-foreground' : ''
                        "
                    >
                        {{ props.name === '' ? props.placeholder : props.name }}
                    </span>

                    <span
                        v-if="isNew"
                        class="shrink-0 rounded border border-primary/40 px-1.5 py-0.5 text-[0.6875rem] text-primary"
                    >
                        New
                    </span>

                    <span
                        v-else-if="selected"
                        class="shrink-0 rounded border border-border px-1.5 py-0.5 text-[0.6875rem] text-faint"
                    >
                        Existing
                    </span>

                    <ChevronsUpDown class="ml-1 size-4 shrink-0 text-faint" />
                </Button>
            </ComboboxTrigger>
        </ComboboxAnchor>

        <ComboboxList
            class="w-(--reka-combobox-trigger-width) min-w-72"
            align="start"
        >
            <ComboboxInput
                v-model="search"
                :placeholder="props.searchPlaceholder"
                :data-test="
                    props.dataTest ? `${props.dataTest}-search` : undefined
                "
            />

            <ComboboxViewport>
                <ComboboxGroup>
                    <ComboboxItem
                        v-for="option in matches"
                        :key="option.value"
                        :value="option.value"
                        :data-test="`option-${option.value}`"
                    >
                        <Check
                            class="size-4 shrink-0"
                            :class="
                                option.value === props.modelValue
                                    ? 'opacity-100'
                                    : 'opacity-0'
                            "
                        />

                        <span class="flex min-w-0 flex-1 items-center gap-1.5">
                            <span class="truncate">{{ option.label }}</span>

                            <template v-if="option.hint">
                                <span
                                    class="shrink-0 text-faint"
                                    aria-hidden="true"
                                >
                                    &middot;
                                </span>
                                <span
                                    class="shrink-0 font-mono text-xs text-faint"
                                >
                                    {{ option.hint }}
                                </span>
                            </template>
                        </span>

                        <slot name="meta" :option="option" />
                    </ComboboxItem>
                </ComboboxGroup>
            </ComboboxViewport>

            <p
                v-if="hiddenCount > 0"
                class="border-t px-3 py-2 text-center text-xs text-faint"
            >
                {{ hiddenCount }} more - keep typing to narrow it down
            </p>

            <!-- Deliberately outside the viewport and outside the filter, so
                 the new-record row neither scrolls away under a long list nor
                 vanishes when the search narrows to nothing. -->
            <ComboboxGroup class="border-t p-1">
                <ComboboxItem :value="NEW_ROW" data-test="option-new">
                    <UserPlus class="size-4 shrink-0 text-primary" />

                    <span class="flex min-w-0 flex-1 flex-col">
                        <span class="truncate text-primary">
                            {{ props.newLabel }}
                        </span>
                        <span
                            v-if="search.trim() !== ''"
                            class="truncate text-xs text-faint"
                        >
                            {{ search.trim() }}
                        </span>
                    </span>
                </ComboboxItem>
            </ComboboxGroup>
        </ComboboxList>
    </Combobox>
</template>
