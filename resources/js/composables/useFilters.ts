import { router } from '@inertiajs/vue3';
import { computed, onScopeDispose, reactive, watch } from 'vue';
import { useVisitPending } from '@/composables/useVisitPending';

type FilterValues = Record<string, string>;

type Options<T extends FilterValues> = {
    /** Where the filtered list lives. */
    url: string;

    /** What each control holds right now, read off the page's `filters` prop. */
    initial: T;

    /**
     * What each control holds when it is asking for nothing: 'all' for a
     * select, '' for a text box. A control sitting on its blank value is left
     * out of the query string, so the URL only ever carries what was chosen.
     */
    blank: T;

    /**
     * The props the server has to rebuild. A keystroke should fetch the rows
     * and nothing else.
     */
    only: string[];

    /**
     * Props that must be replaced rather than merged. Only a list that grows
     * as it scrolls needs this: filtering is asking a different question, so
     * the answer starts again rather than piling onto the last one.
     */
    reset?: string[];

    /** Milliseconds of quiet before the request goes out. */
    delay?: number;

    preserveScroll?: boolean;
};

/**
 * The filter bar a list screen wears: controls that reload the table a beat
 * after the last keystroke, a way to ask whether anything is set, a reset, and
 * a `processing` flag to hang the table's loading state on.
 */
export function useFilters<T extends FilterValues>(options: Options<T>) {
    const { url, initial, blank, only } = options;
    const reset = options.reset ?? [];
    const delay = options.delay ?? 300;
    const preserveScroll = options.preserveScroll ?? true;

    const filters = reactive({ ...initial }) as T;

    const query = computed<FilterValues>(() =>
        Object.fromEntries(
            Object.entries(filters).filter(
                ([key, value]) => value !== blank[key],
            ),
        ),
    );

    const hasFilters = computed(() => Object.keys(query.value).length > 0);

    /*
      Held here rather than per-visit so the pager and the page-size box, which
      reload the same rows, raise the same flag as the filter bar.
    */
    const { processing, reporter } = useVisitPending();

    let timer: ReturnType<typeof setTimeout> | undefined;

    /**
     * What the server was last asked for. `apply` sends before the watcher has
     * run, so without this the same request would go out twice — once now and
     * once when the watcher notices the change that caused it.
     */
    let sent = JSON.stringify(query.value);

    function visit() {
        sent = JSON.stringify(query.value);

        router.get(url, query.value, {
            only,
            reset,
            preserveState: true,
            preserveScroll,
            replace: true,
            onStart: reporter.onStart,
            onFinish: reporter.onFinish,
        });
    }

    /** Send now, for a reset, where a pause reads as lag. */
    function apply(overrides: Partial<T> = {}) {
        Object.assign(filters, overrides);
        clearTimeout(timer);
        visit();
    }

    function clear() {
        apply(blank);
    }

    watch(query, () => {
        if (JSON.stringify(query.value) === sent) {
            return;
        }

        clearTimeout(timer);
        timer = setTimeout(visit, delay);
    });

    onScopeDispose(() => clearTimeout(timer));

    return { filters, query, hasFilters, processing, apply, clear };
}
