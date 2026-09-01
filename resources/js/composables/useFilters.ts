import { router } from '@inertiajs/vue3';
import { computed, onScopeDispose, reactive, watch } from 'vue';
import { useVisitPending } from '@/composables/useVisitPending';

type FilterValues = Record<string, string>;

type Options<T extends FilterValues> = {
    url: string;

    initial: T;

    /* What each control holds when asking for nothing: 'all' for a select, ''
       for a text box. A control on its blank value is left out of the query
       string, so the URL only ever carries what was chosen. */
    blank: T;

    only: string[];

    /* Props to replace rather than merge - only a list that grows as it
       scrolls needs this, since filtering asks a different question. */
    reset?: string[];

    /** Milliseconds of quiet before the request goes out. */
    delay?: number;

    preserveScroll?: boolean;
};

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

    const { processing, reporter } = useVisitPending();

    let timer: ReturnType<typeof setTimeout> | undefined;

    /* What the server was last asked for. `apply` sends before the watcher has
       run, so without this the same request goes out twice - once now, once
       when the watcher notices the change that caused it. */
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

    /** Sends now rather than after the delay, where a pause would read as lag. */
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
