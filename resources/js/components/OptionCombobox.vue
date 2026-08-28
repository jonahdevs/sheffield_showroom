<script setup lang="ts">
import { Check, ChevronsUpDown, ImageOff } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Combobox,
    ComboboxAnchor,
    ComboboxEmpty,
    ComboboxGroup,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
    ComboboxTrigger,
    ComboboxViewport,
} from '@/components/ui/combobox';
import { countMatches, matchOptions } from '@/lib/options';

/** The chosen record's id, or null while nothing is chosen. */
const model = defineModel<number | null>({ required: true });

const props = withDefaults(
    defineProps<{
        options: App.Data.OptionData[];
        id?: string;
        placeholder?: string;
        searchPlaceholder?: string;
        emptyText?: string;
        dataTest?: string;
    }>(),
    {
        id: undefined,
        placeholder: 'Choose one',
        searchPlaceholder: 'Type to search',
        emptyText: 'Nothing matches that.',
        dataTest: undefined,
    },
);

const open = ref(false);
const search = ref('');

const selected = computed(
    () => props.options.find((option) => option.value === model.value) ?? null,
);

/*
  `ignore-filter` on the root, and the narrowing done here instead. Reka's own
  filter keeps every item mounted and hides the ones that miss, which for a
  catalogue of over a thousand is a thousand rows built each time the box
  opens. This renders a screenful and counts the rest.
*/
const matches = computed(() => matchOptions(props.options, search.value));

/*
  Whether this list is one with pictures at all. Decided across the whole list
  rather than per row, so a customer list is not indented by a column of empty
  frames it will never fill.
*/
const hasImages = computed(() =>
    props.options.some((option) => (option.image_url ?? null) !== null),
);

const hiddenCount = computed(
    () => countMatches(props.options, search.value) - matches.value.length,
);

/** A term left over from last time reads as a box that lost its list. */
watch(open, (isOpen) => {
    if (!isOpen) {
        search.value = '';
    }
});
</script>

<!--
  One record picked out of many, by name or by the line under it - a customer
  is found by their phone number as often as by their name, so both are
  searched.
-->
<template>
    <Combobox v-model="model" v-model:open="open" ignore-filter class="w-full">
        <ComboboxAnchor as-child class="w-full">
            <ComboboxTrigger as-child>
                <Button
                    :id="props.id"
                    type="button"
                    variant="outline"
                    class="w-full justify-start px-3 font-normal"
                    :data-test="props.dataTest"
                >
                    <img
                        v-if="selected?.image_url"
                        :src="selected.image_url"
                        alt=""
                        class="size-5 shrink-0 rounded-sm object-contain"
                    />

                    <span
                        class="min-w-0 flex-1 truncate text-left"
                        :class="selected ? '' : 'text-muted-foreground'"
                    >
                        {{ selected?.label ?? props.placeholder }}
                    </span>

                    <span
                        v-if="selected?.hint"
                        class="shrink-0 font-mono text-xs text-faint"
                    >
                        {{ selected.hint }}
                    </span>

                    <ChevronsUpDown class="ml-1 size-4 shrink-0 text-faint" />
                </Button>
            </ComboboxTrigger>
        </ComboboxAnchor>

        <ComboboxList
            class="w-(--reka-combobox-trigger-width) min-w-64"
            align="start"
        >
            <ComboboxInput
                v-model="search"
                :placeholder="props.searchPlaceholder"
                :data-test="
                    props.dataTest ? `${props.dataTest}-search` : undefined
                "
            />

            <ComboboxEmpty class="text-muted-foreground">
                {{ props.emptyText }}
            </ComboboxEmpty>

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
                                option.value === model
                                    ? 'opacity-100'
                                    : 'opacity-0'
                            "
                        />

                        <!-- The frame is drawn whether or not there is a
                             picture in it, so the labels stay on one vertical
                             line down the list. -->
                        <span
                            v-if="hasImages"
                            class="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded bg-muted"
                            aria-hidden="true"
                        >
                            <img
                                v-if="option.image_url"
                                :src="option.image_url"
                                alt=""
                                loading="lazy"
                                class="size-full object-contain"
                            />
                            <ImageOff v-else class="size-3.5 text-faint" />
                        </span>

                        <!-- Stacked rather than side by side: in a side
                             panel a code sharing the row leaves the name
                             too little to be read at all. -->
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span class="truncate">{{ option.label }}</span>
                            <span
                                v-if="option.hint"
                                class="truncate font-mono text-xs text-faint"
                            >
                                {{ option.hint }}
                            </span>
                        </span>
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
        </ComboboxList>
    </Combobox>
</template>
