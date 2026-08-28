<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useQueryString } from '@/composables/useQueryString';
import { useVisitReporter } from '@/composables/useVisitPending';

const props = withDefaults(
    defineProps<{
        sizes: number[];
        current: number;
        triggerClass?: string;
    }>(),
    { triggerClass: 'w-auto min-w-16 tabular-nums' },
);

const { withQuery } = useQueryString();

/** Resizing the page is a reload like any other, so it raises the same flag. */
const reporter = useVisitReporter();

function change(size: unknown): void {
    if (size === null || size === undefined) {
        return;
    }

    const url = withQuery((query) => {
        query.set('per_page', String(size));
        query.delete('page');
    });

    router.get(
        url,
        {},
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: reporter.onStart,
            onFinish: reporter.onFinish,
        },
    );
}
</script>

<template>
    <Select
        :model-value="String(props.current)"
        @update:model-value="change($event)"
    >
        <SelectTrigger
            size="sm"
            :class="props.triggerClass"
            aria-label="Rows per page"
            data-test="per-page"
        >
            <SelectValue />
        </SelectTrigger>
        <SelectContent>
            <SelectItem
                v-for="size in props.sizes"
                :key="size"
                :value="String(size)"
                class="tabular-nums"
            >
                {{ size }}
            </SelectItem>
        </SelectContent>
    </Select>
</template>
