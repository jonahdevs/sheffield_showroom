<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

const props = withDefaults(
    defineProps<{
        items: NavItem[];
        label?: string;
    }>(),
    { label: 'Platform' },
);

const { isCurrentOrParentUrl } = useCurrentUrl();

/**
 * One row's href as a bare path. Wayfinder hrefs may carry an origin while
 * `activeFor` is a plain path, so an absolute URL would otherwise measure
 * deeper purely for having a hostname.
 */
function pathOf(href: NavItem['href'] | string): string {
    const url = toUrl(href);

    if (!url.startsWith('http')) {
        return url;
    }

    try {
        return new URL(url).pathname;
    } catch {
        return url;
    }
}

/**
 * How specifically this row matches the page, in path segments, or 0 for no
 * match. A row claims the whole section under it, plus any section it names
 * through `activeFor` for screens filed under a different path.
 */
function matchDepth(item: NavItem): number {
    return [item.href, ...(item.activeFor ?? [])]
        .filter((href) => isCurrentOrParentUrl(href as NavItem['href']))
        .reduce((deepest, href) => {
            const segments = pathOf(href).replace(/\/+$/, '').split('/').length;

            return Math.max(deepest, segments);
        }, 0);
}

/**
 * Rows nest - Rewards and Redeem both live under Campaigns' `/admin/rewards` -
 * so only the deepest match lights, or all three light at once.
 */
const deepestMatch = computed(() =>
    props.items.reduce(
        (deepest, item) => Math.max(deepest, matchDepth(item)),
        0,
    ),
);

function isActive(item: NavItem): boolean {
    const depth = matchDepth(item);

    return depth > 0 && depth === deepestMatch.value;
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
