import type { InertiaLinkProps } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import type { ComputedRef, DeepReadonly } from 'vue';
import { computed, readonly } from 'vue';
import { toUrl } from '@/lib/utils';

export type UseCurrentUrlReturn = {
    currentUrl: DeepReadonly<ComputedRef<string>>;
    isCurrentUrl: (
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
        currentUrl?: string,
        startsWith?: boolean,
    ) => boolean;
    isCurrentOrParentUrl: (
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
        currentUrl?: string,
    ) => boolean;
    whenCurrentUrl: <T, F = null>(
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
        ifTrue: T,
        ifFalse?: F,
    ) => T | F;
};

const page = usePage();
const currentUrlReactive = computed(
    () =>
        new URL(
            page.url,
            typeof window !== 'undefined'
                ? window.location.origin
                : 'http://localhost',
        ).pathname,
);

export function useCurrentUrl(): UseCurrentUrlReturn {
    /**
     * Whether a link points at the page being looked at.
     *
     * `startsWith` widens that to the section rather than the page: a nav item
     * for the list is still the item you are under while you are three pages
     * deep in it, and a sidebar that goes dark the moment somebody opens a
     * create form has stopped saying where they are.
     */
    function isCurrentUrl(
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
        currentUrl?: string,
        startsWith: boolean = false,
    ) {
        const urlToCompare = currentUrl ?? currentUrlReactive.value;
        const urlString = toUrl(urlToCheck);

        /*
          A prefix match is on whole segments, not on characters. Plain
          `startsWith` would light `/admin/product` up on `/admin/products`,
          which is the kind of thing that only shows itself once two sections
          are named similarly and then reads as two links being active at once.
        */
        const comparePath = (path: string): boolean => {
            if (path === urlToCompare) {
                return true;
            }

            return (
                startsWith &&
                urlToCompare.startsWith(path.replace(/\/$/, '') + '/')
            );
        };

        if (!urlString.startsWith('http')) {
            return comparePath(urlString);
        }

        try {
            const absoluteUrl = new URL(urlString);

            return comparePath(absoluteUrl.pathname);
        } catch {
            return false;
        }
    }

    function isCurrentOrParentUrl(
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
        currentUrl?: string,
    ) {
        return isCurrentUrl(urlToCheck, currentUrl, true);
    }

    /**
     * One of two values, by whether the link owns the page.
     *
     * Section-wide for the same reason the nav items are: its only caller is a
     * navigation highlight, and a highlight that only holds on the index is a
     * highlight that is wrong on every page below it.
     */
    function whenCurrentUrl(
        urlToCheck: NonNullable<InertiaLinkProps['href']>,
        ifTrue: any,
        ifFalse: any = null,
    ) {
        return isCurrentOrParentUrl(urlToCheck) ? ifTrue : ifFalse;
    }

    return {
        currentUrl: readonly(currentUrlReactive),
        isCurrentUrl,
        isCurrentOrParentUrl,
        whenCurrentUrl,
    };
}
