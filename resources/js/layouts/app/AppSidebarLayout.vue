<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
</script>

<!--
  The shell: rail, topbar, and whatever the page puts between them. Both the
  rail and the bar are the page's own white; what the content sits on is the
  caller's to decide, which is why the page surface lives one level up in
  AppLayout rather than here.
-->
<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="min-w-0 overflow-x-clip">
            <AppSidebarHeader />
            <slot />
        </AppContent>
        <Toaster />
    </AppShell>
</template>
