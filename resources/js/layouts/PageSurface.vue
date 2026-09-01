<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    { breadcrumbs: () => [] },
);
</script>

<!--
  `@container/page` is the ruler page content should measure against: behind
  the rail the window is wider than the box a page actually gets, so viewport
  breakpoints promise room it does not have. Descendants ask `@.../page:`; this
  element keeps viewport breakpoints because it cannot query its own container.
-->
<template>
    <div
        class="@container/page flex flex-1 flex-col gap-5 px-4.5 pt-5 pb-10 lg:px-7 lg:pt-6"
    >
        <Breadcrumbs v-if="breadcrumbs.length" :breadcrumbs="breadcrumbs" />
        <slot />
    </div>
</template>
