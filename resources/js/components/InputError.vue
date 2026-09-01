<script setup lang="ts">
defineProps<{
    message?: string;
}>();
</script>

<!--
  `v-if` rather than `v-show`: an empty alert region that later fills is read
  inconsistently across screen readers.
-->
<template>
    <p v-if="message" class="mt-1.5 text-xs text-destructive" role="alert">
        {{ message }}
    </p>
</template>
