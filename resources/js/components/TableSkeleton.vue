<script setup lang="ts">
withDefaults(
    defineProps<{
        rows?: number;

        /** Cells per row. The first is drawn wider, as the label column. */
        columns?: number;
    }>(),
    { rows: 5, columns: 4 },
);
</script>

<template>
    <div class="animate-pulse" aria-hidden="true">
        <div class="flex items-center gap-3 border-b border-border pb-2">
            <span
                v-for="column in columns"
                :key="column"
                class="min-w-0"
                :class="column === 1 ? 'flex-[2]' : 'flex-1'"
            >
                <span
                    class="block h-2.5 rounded bg-muted"
                    :class="column === 1 ? 'w-3/5' : 'mx-auto w-1/2'"
                />
            </span>
        </div>

        <div
            v-for="row in rows"
            :key="row"
            class="flex items-center gap-3 border-b border-border py-3 last:border-0"
        >
            <span
                v-for="column in columns"
                :key="column"
                class="min-w-0"
                :class="column === 1 ? 'flex-[2]' : 'flex-1'"
            >
                <span
                    class="block h-3 rounded bg-muted"
                    :class="column === 1 ? 'w-11/12' : 'mx-auto w-2/3'"
                />
            </span>
        </div>
    </div>
</template>
