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
  The page's own padding, and the crumb trail above it.

  The trail sits here rather than in the topbar because it belongs to the page,
  not to the shell: it changes with every navigation, it can be two words or
  four, and in a fixed-height header it would either clip or push the account
  block around. Down here it has the page's width to spend.

  `flex-1` and no `h-full`, so a page that runs short does not force the
  surface past the fold.

  `@container/page` is the ruler page content should measure against. Behind a
  208px rail the window is wider than the box a page actually gets, so a
  viewport breakpoint promises room the page does not have. Descendants ask
  `@.../page:`; this element keeps viewport breakpoints for its own gutter
  because an element cannot query its own container.
-->
<template>
    <div
        class="@container/page flex flex-1 flex-col gap-5 px-4.5 pt-5 pb-10 lg:px-7 lg:pt-6"
    >
        <Breadcrumbs v-if="breadcrumbs.length" :breadcrumbs="breadcrumbs" />
        <slot />
    </div>
</template>
