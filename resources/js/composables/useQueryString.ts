import { usePage } from '@inertiajs/vue3';

/**
 * Builds a URL from the one currently on screen, so a control that changes a
 * single parameter keeps every other filter the page is already wearing.
 */
export function useQueryString() {
    const page = usePage();

    function withQuery(mutate: (query: URLSearchParams) => void): string {
        const [path, existing] = page.url.split('?');
        const query = new URLSearchParams(existing ?? '');

        mutate(query);

        const suffix = query.toString();

        return suffix === '' ? path : `${path}?${suffix}`;
    }

    return { withQuery };
}
