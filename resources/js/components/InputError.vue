<script setup lang="ts">
defineProps<{
    message?: string;
}>();
</script>

<!--
  `role="alert"` so the message is announced when validation puts it there,
  rather than only being seen. `v-if` rather than `v-show`: an empty alert
  region that later fills is read inconsistently across screen readers.
-->
<template>
    <p v-if="message" class="mt-1.5 text-xs text-destructive" role="alert">
        {{ message }}
    </p>
</template>
