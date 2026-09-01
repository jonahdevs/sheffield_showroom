import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    /**
     * Extra sections this item owns, for a screen living under a different
     * address than the row that leads to it. Matched by whole path segment,
     * like `href`, so pages below one still light the row.
     */
    activeFor?: NonNullable<InertiaLinkProps['href']>[];
};
