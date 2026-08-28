<script setup lang="ts">
import AppSidebarLayout from '@/layouts/app/AppSidebarLayout.vue';
import PageSurface from '@/layouts/PageSurface.vue';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();
</script>

<!--
  `bg-muted` is what separates the content from the shell. The rail and the
  topbar are the page's white; the surface the content sits on is a step off
  it, so a white card lifts off the page instead of dissolving into it and the
  1px border stops being the only thing doing the work.
-->
<template>
    <AppSidebarLayout>
        <PageSurface :breadcrumbs="breadcrumbs" class="bg-muted">
            <slot />
        </PageSurface>
    </AppSidebarLayout>
</template>
