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
        /** What the box reads: the record's name, or the new one typed in. */
        name: string;
        id?: string;
        placeholder?: string;
        searchPlaceholder?: string;
        /** The last row's own label. It never filters out and never moves. */
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
    /** One of the records, chosen off the list. */
    pick: [option: T];
    /** Nobody on the list - what was searched for is their name. */
    create: [name: string];
}>();

const open = ref(false);
const search = ref('');

const selected = computed(
    () =>
        props.options.find((option) => option.value === props.modelValue) ??
        null,
);

/*
  `ignore-filter` on the root, and the narrowing done here instead. Reka's own
  filter keeps every item mounted and hides the ones that miss, which for a
  list running to hundreds is hundreds of rows built each time the box opens.
  This renders a screenful and counts the rest.
*/
const matches = computed(() => matchOptions(props.options, search.value));

const hiddenCount = computed(
    () => countMatches(props.options, search.value) - matches.value.length,
);

/**
 * A name with no record behind it: typed here, filed on save.
 *
 * Worth saying out loud on the closed box, because it is the difference
 * between adding a visit to somebody's history and starting a new one.
 */
const isNew = computed(() => props.modelValue === null && props.name !== '');

/**
 * The value of the last row.
 *
 * A string among numeric ids, so it cannot collide with a record - and the
 * root sends it back through the same channel as any other pick.
 */
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

/**
 * A term left over from last time reads as a box that lost its list, so the
 * search empties on the way out.
 *
 * On the way in it is seeded with a name that has no record behind it, which
 * is what makes a typo fixable: the box reopens on what was typed, ready to
 * be corrected and carried across again. A name that came off the list is not
 * seeded - that search is for a different customer, not a correction to this
 * one.
 */
watch(open, (isOpen) => {
    if (!isOpen) {
        search.value = '';

        return;
    }

    search.value = isNew.value ? props.name : '';
});
</script>

<!--
  One record picked out of many, or a name for somebody who is not among them.

  The last row is the whole point: it is rendered outside the scrolling
  viewport and outside the filter, so it neither scrolls away under a long
  list nor disappears as the search narrows to nothing. Whatever was being
  searched for becomes the name of whoever was not found - the typing is not
  thrown away just because it matched nobody.
-->
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

                    <!-- Which of the two this is, said the same way either
                         way. A badge against a number read as two different
                         kinds of fact; two badges read as one answer with two
                         values, which is what it is. -->
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

                        <!-- On the name's own line rather than under it: two
                             people share a name often enough that the number
                             is what tells them apart, and it can only do that
                             while it is being read alongside. The name gives
                             way first when the row runs out of room - the
                             number is the half that disambiguates. -->
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

            <!-- Said rather than silently truncated: a list that stops at
                 fifty with no explanation reads as a missing record. -->
            <p
                v-if="hiddenCount > 0"
                class="border-t px-3 py-2 text-center text-xs text-faint"
            >
                {{ hiddenCount }} more - keep typing to narrow it down
            </p>

            <ComboboxGroup class="border-t p-1">
                <ComboboxItem :value="NEW_ROW" data-test="option-new">
                    <UserPlus class="size-4 shrink-0 text-primary" />

                    <span class="flex min-w-0 flex-1 flex-col">
                        <span class="truncate text-primary">
                            {{ props.newLabel }}
                        </span>
                        <!-- What the row is about to carry across, shown
                             before it does: the search is the name. -->
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
