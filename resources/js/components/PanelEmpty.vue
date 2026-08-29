<script setup lang="ts">
withDefaults(
    defineProps<{
        /** How tall to hold the well open, so a row of panels stays level. */
        height?: number;
    }>(),
    { height: 0 },
);
</script>

<!--
  What a panel says when the window it was asked about holds nothing.

  Kept to the height the chart would have taken: a dashboard whose panels
  collapse to a line of text the moment a quiet week is picked reads as a page
  that failed to load rather than a showroom that was closed.
-->
<template>
    <div
        class="m-5 flex flex-1 items-center justify-center rounded-lg bg-muted/60 p-6 text-center"
        :style="height > 0 ? { minHeight: `${height}px` } : undefined"
    >
        <p class="text-sm text-muted-foreground"><slot /></p>
    </div>
</template>
