<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const name = usePage().props.name;
</script>

<template>
    <!--
        White in both themes, not bg-sidebar-primary: that token is neutral-900
        in light mode, which buries the flame's navy half. The mark is two fixed
        colours and cannot recolour its way off a dark ground.
    -->
    <div
        class="flex aspect-square size-8 items-center justify-center rounded-md bg-white ring-1 ring-sidebar-border"
    >
        <AppLogoIcon class="size-7" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-tight font-semibold">{{
            name
        }}</span>
    </div>
</template>
