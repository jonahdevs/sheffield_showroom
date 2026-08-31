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
     * Sections this item owns besides its own, for a screen that lives under a
     * different address than the row that leads to it. Matched by whole path
     * segment, like `href` is, so a create or edit page below one of them
     * still lights the row that got you there.
     */
    activeFor?: NonNullable<InertiaLinkProps['href']>[];
};
