<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import {
    Pagination,
    PaginationContent,
    PaginationEllipsis,
    PaginationItem,
    PaginationNext,
    PaginationPrevious,
} from '@/components/ui/pagination';
import { useQueryString } from '@/composables/useQueryString';
import { useVisitReporter } from '@/composables/useVisitPending';

export interface Paginator {
    current_page: number;
    last_page: number;
    per_page: number;
    from: number | null;
    to: number | null;
    total: number;
}

const props = defineProps<{
    meta: Paginator;
    /** What is being counted, for the summary line. */
    label?: string;
}>();

const { withQuery } = useQueryString();

const reporter = useVisitReporter();

function urlForPage(target: number): string {
    return withQuery((query) => {
        if (target <= 1) {
            query.delete('page');

            return;
        }

        query.set('page', String(target));
    });
}

const noun = computed(() => props.label ?? 'results');

const showing = computed(() => {
    if (props.meta.total === 0) {
        return `No ${noun.value}`;
    }

    return `Showing ${props.meta.from}-${props.meta.to} of ${props.meta.total} ${noun.value}`;
});
</script>

<!--
  The pager every list shares. Pages are Inertia links rather than clicks, so a
  page is a URL somebody can send to somebody else, and every visit reports
  through the list's own pending flag.
-->
<template>
    <div
        v-if="props.meta.total > 0"
        class="flex flex-wrap items-center justify-between gap-3"
        data-test="pagination"
    >
        <div class="flex flex-wrap items-center gap-3">
            <p class="text-xs text-faint tabular-nums">
                {{ showing }}
            </p>
            <slot />
        </div>

        <Pagination
            v-if="props.meta.last_page > 1"
            :page="props.meta.current_page"
            :total="props.meta.total"
            :items-per-page="props.meta.per_page"
            :sibling-count="1"
            show-edges
            class="mx-0 w-auto justify-end"
        >
            <PaginationContent v-slot="{ items }">
                <PaginationPrevious v-if="props.meta.current_page > 1" as-child>
                    <Link
                        :href="urlForPage(props.meta.current_page - 1)"
                        aria-label="Previous page"
                        v-bind="reporter"
                        preserve-scroll
                        preserve-state
                    >
                        <ChevronLeft />
                        <span class="hidden sm:block">Previous</span>
                    </Link>
                </PaginationPrevious>
                <PaginationPrevious v-else />

                <template v-for="(item, index) in items" :key="index">
                    <PaginationItem
                        v-if="item.type === 'page'"
                        :value="item.value"
                        :is-active="item.value === props.meta.current_page"
                        as-child
                    >
                        <Link
                            :href="urlForPage(item.value)"
                            :aria-current="
                                item.value === props.meta.current_page
                                    ? 'page'
                                    : undefined
                            "
                            :aria-label="`Page ${item.value}`"
                            v-bind="reporter"
                            preserve-scroll
                            preserve-state
                        >
                            {{ item.value }}
                        </Link>
                    </PaginationItem>
                    <PaginationEllipsis v-else :index="index" />
                </template>

                <PaginationNext
                    v-if="props.meta.current_page < props.meta.last_page"
                    as-child
                >
                    <Link
                        :href="urlForPage(props.meta.current_page + 1)"
                        aria-label="Next page"
                        v-bind="reporter"
                        preserve-scroll
                        preserve-state
                    >
                        <span class="hidden sm:block">Next</span>
                        <ChevronRight />
                    </Link>
                </PaginationNext>
                <PaginationNext v-else />
            </PaginationContent>
        </Pagination>
    </div>
</template>
