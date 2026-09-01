<script setup lang="ts">
withDefaults(
    defineProps<{
        /** How tall to hold the well open, so a row of panels stays level. */
        height?: number;
    }>(),
    { height: 0 },
);
</script>

<template>
    <div
        class="m-5 flex flex-1 items-center justify-center rounded-lg bg-muted/60 p-6 text-center"
        :style="height > 0 ? { minHeight: `${height}px` } : undefined"
    >
        <p class="text-sm text-muted-foreground"><slot /></p>
    </div>
</template>
