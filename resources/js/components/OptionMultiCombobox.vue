<script setup lang="ts">
import { Check, ChevronsUpDown, ImageOff, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
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

/** The chosen ids. Empty is a valid answer - nothing was shown. */
const model = defineModel<number[]>({ required: true });

const props = withDefaults(
    defineProps<{
        options: App.Data.OptionData[];
        /**
         * Records already chosen that may no longer be in `options` - a
         * product dropped from the catalogue after the visit was logged still
         * has to show its name rather than a bare id.
         */
        selected?: App.Data.OptionData[];
        id?: string;
        placeholder?: string;
        searchPlaceholder?: string;
        emptyText?: string;
        /**
         * Whether the chips under the box are left off.
         *
         * For a caller that lists the picks itself and in more detail - a
         * table with a column per thing worth saying about them - where chips
         * saying the same names above it are a second answer to read.
         */
        hideChosen?: boolean;
        dataTest?: string;
    }>(),
    {
        selected: () => [],
        id: undefined,
        placeholder: 'Choose any number',
        searchPlaceholder: 'Type to search',
        emptyText: 'Nothing matches that.',
        hideChosen: false,
        dataTest: undefined,
    },
);

const open = ref(false);
const search = ref('');

/*
  Every option this box can name, whether it is still on offer or only still
  attached. Keyed by value, so a record in both lists is held once.
*/
const known = computed(() => {
    const map = new Map<number, App.Data.OptionData>();

    for (const option of [...props.options, ...props.selected]) {
        map.set(option.value, option);
    }

    return map;
});

/** The chips, in the order they were chosen. */
const chosen = computed(() =>
    model.value
        .map((value) => known.value.get(value))
        .filter(
            (option): option is App.Data.OptionData => option !== undefined,
        ),
);

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

watch(open, (isOpen) => {
    if (!isOpen) {
        search.value = '';
    }
});

function remove(value: number) {
    model.value = model.value.filter((chosenValue) => chosenValue !== value);
}
</script>

<!--
  Several records picked out of many, shown as chips under the box so what has
  been chosen is readable without opening it again.

  The list stays open on each pick, because choosing what a customer was shown
  is several picks in a row and closing after each one makes it several trips.
-->
<template>
    <div class="flex flex-col gap-2.5">
        <Combobox
            v-model="model"
            v-model:open="open"
            multiple
            ignore-filter
            class="w-full"
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
                                chosen.length > 0 ? '' : 'text-muted-foreground'
                            "
                        >
                            {{
                                chosen.length === 0
                                    ? props.placeholder
                                    : `${chosen.length} chosen`
                            }}
                        </span>

                        <ChevronsUpDown
                            class="ml-1 size-4 shrink-0 text-faint"
                        />
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
                            <!-- A checkbox rather than a tick that fades in
                                 and out: this box takes several answers, and
                                 a square that is either filled or empty says
                                 so before anything is chosen. -->
                            <div
                                class="pointer-events-none size-4 shrink-0 rounded-[4px] border border-input transition-all select-none data-[selected=true]:border-primary data-[selected=true]:bg-primary data-[selected=true]:text-primary-foreground *:[svg]:opacity-0 data-[selected=true]:*:[svg]:opacity-100"
                                :data-selected="model.includes(option.value)"
                            >
                                <Check class="size-3.5 text-current" />
                            </div>

                            <!-- The frame is drawn whether or not there is a
                                 picture in it, so the labels stay on one
                                 vertical line down the list. -->
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

                <p
                    v-if="hiddenCount > 0"
                    class="border-t px-3 py-2 text-center text-xs text-faint"
                >
                    {{ hiddenCount }} more - keep typing to narrow it down
                </p>
            </ComboboxList>
        </Combobox>

        <ul
            v-if="!props.hideChosen && chosen.length > 0"
            class="flex flex-wrap gap-1.5"
        >
            <li v-for="option in chosen" :key="option.value">
                <Badge
                    variant="outline"
                    class="gap-1 bg-card py-1 pr-1"
                    :class="option.image_url ? 'pl-1' : 'pl-2.5'"
                >
                    <img
                        v-if="option.image_url"
                        :src="option.image_url"
                        alt=""
                        loading="lazy"
                        class="size-5 shrink-0 rounded-sm bg-background object-contain"
                    />
                    <span class="max-w-52 truncate">{{ option.label }}</span>
                    <button
                        type="button"
                        class="rounded-sm p-0.5 text-faint transition-colors hover:bg-muted hover:text-foreground"
                        :aria-label="`Remove ${option.label}`"
                        :data-test="`remove-${option.value}`"
                        @click="remove(option.value)"
                    >
                        <X class="size-3" />
                    </button>
                </Badge>
            </li>
        </ul>
    </div>
</template>
