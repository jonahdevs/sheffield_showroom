<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

withDefaults(
    defineProps<{
        items: NavItem[];
        label?: string;
    }>(),
    { label: 'Platform' },
);

const { isCurrentOrParentUrl } = useCurrentUrl();

/**
 * Whether this row is the one you are under.
 *
 * `isCurrentOrParentUrl` already holds the highlight down a section, so a
 * create or edit form does not put the rail out. What it cannot know is that a
 * row sometimes leads to screens filed elsewhere - the accounts under
 * `/admin/users` are reached from Roles, because that is where the list of
 * people lives - so an item may name those sections itself.
 */
function isActive(item: NavItem): boolean {
    return (
        isCurrentOrParentUrl(item.href) ||
        (item.activeFor ?? []).some((section) => isCurrentOrParentUrl(section))
    );
}
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>{{ label }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="isActive(item)"
                    :tooltip="item.title"
                >
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
