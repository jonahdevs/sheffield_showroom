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
 * One row's href as a bare path, for measuring how deep it reaches.
 *
 * Wayfinder hands back an object or a string and either may carry an origin,
 * while `activeFor` is written as a plain path. Comparing those as they arrive
 * would make an absolute URL look deeper than a relative one purely for having
 * a hostname in front of it.
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
 * How specifically this row matches the page, in path segments, or 0 where it
 * does not match at all.
 *
 * A row claims the section under it, not only its own page, so a create or an
 * edit form does not put the rail out. What it cannot know is that a row
 * sometimes leads to screens filed elsewhere - the accounts under
 * `/admin/users` are reached from Roles, because that is where the list of
 * people lives - so an item may name those sections itself through `activeFor`,
 * and a match on one of those counts as a match on that section's depth.
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
 * The depth of the closest match anywhere in this group.
 *
 * Rows nest: Campaigns is `/admin/rewards` and both Rewards and Redeem live
 * under it, so a section-wide match lights all three at once and the rail
 * stops saying which screen you are on. Whichever row reaches deepest into the
 * current path wins, and the shallower parents step aside.
 *
 * Worked out rather than declared per row, because the alternative is every
 * row listing the siblings it must not claim - a list that is correct only
 * until somebody adds the next one.
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
