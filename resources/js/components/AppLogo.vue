<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const name = usePage().props.name;
</script>

<template>
    <!--
        The chip is white in both themes rather than bg-sidebar-primary. That token flips
        to neutral-900 in light mode, which would bury the flame's navy inner half in a
        near-black square; the mark is two fixed colours and cannot recolour its way out
        of a dark ground, so it gets a light one instead. The ring keeps the white chip
        from dissolving into the light sidebar.
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
