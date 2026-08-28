import { inject, onScopeDispose, provide, ref } from 'vue';
import type { InjectionKey } from 'vue';

/** The `onStart`/`onFinish` pair a visit is handed so it reports itself. */
export type VisitReporter = {
    onStart: () => void;
    onFinish: () => void;
};

const VisitPendingKey = Symbol('visit-pending') as InjectionKey<VisitReporter>;

/**
 * Whether a list is waiting on the server, and the hooks that say so.
 *
 * The owner of a list — the page, or whatever called `useFilters` — holds the
 * flag, and every reload that belongs to that list reports through it: the
 * filter bar, the pager, the page-size box. The reporter is handed down the
 * tree so those controls need no wiring at the call site.
 */
export function useVisitPending() {
    const processing = ref(false);

    /**
     * Counted, not a plain boolean. These visits overlap: a debounced filter
     * can put a second request on the wire before the first one answers, and a
     * pager click can land on top of both. Flipping the flag off in the first
     * `onFinish` would clear the table's loading state while it is still
     * waiting on the later request, which is the stale-rows flicker we are
     * here to remove.
     */
    let inFlight = 0;

    const reporter: VisitReporter = {
        onStart: () => {
            inFlight += 1;
            processing.value = true;
        },
        onFinish: () => {
            inFlight = Math.max(0, inFlight - 1);
            processing.value = inFlight > 0;
        },
    };

    /*
      A visit outlives the component that sent it — Inertia still calls back
      after a redirect tears the page down — so the count is dropped here
      rather than left to be decremented against a list nobody is looking at.
    */
    onScopeDispose(() => {
        inFlight = 0;
        processing.value = false;
    });

    provide(VisitPendingKey, reporter);

    return { processing, reporter };
}

/**
 * For a control that reloads the list it sits in — the pager, the page-size
 * box. No-ops when it is used outside a list that tracks its own visits, so
 * both components stay usable on their own.
 */
export function useVisitReporter(): VisitReporter {
    return inject(VisitPendingKey, {
        onStart: () => {},
        onFinish: () => {},
    });
}
