<script setup lang="ts">
import AppSidebarLayout from '@/layouts/app/AppSidebarLayout.vue';
import PageSurface from '@/layouts/PageSurface.vue';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();
</script>

<template>
    <AppSidebarLayout>
        <PageSurface :breadcrumbs="breadcrumbs" class="bg-muted">
            <slot />
        </PageSurface>
    </AppSidebarLayout>
</template>
