import { inject, onScopeDispose, provide, ref } from 'vue';
import type { InjectionKey } from 'vue';

export type VisitReporter = {
    onStart: () => void;
    onFinish: () => void;
};

const VisitPendingKey = Symbol('visit-pending') as InjectionKey<VisitReporter>;

/**
 * Whether a list is waiting on the server. The reporter is provided down the
 * tree, so the pager and the page-size box report through the list's own flag
 * without any wiring at the call site.
 */
export function useVisitPending() {
    const processing = ref(false);

    /* Counted, not a plain boolean: these visits overlap, so flipping the flag
       off in the first `onFinish` would clear the loading state while a later
       request is still out - the stale-rows flicker this exists to remove. */
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

    /* A visit outlives the component that sent it - Inertia still calls back
       after a redirect tears the page down - so the count is dropped here. */
    onScopeDispose(() => {
        inFlight = 0;
        processing.value = false;
    });

    provide(VisitPendingKey, reporter);

    return { processing, reporter };
}

/** No-ops outside a list that tracks its own visits, so a control that
 *  reloads the list it sits in stays usable on its own. */
export function useVisitReporter(): VisitReporter {
    return inject(VisitPendingKey, {
        onStart: () => {},
        onFinish: () => {},
    });
}
