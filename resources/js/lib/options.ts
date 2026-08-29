/**
 * How a combobox narrows its list.
 *
 * Shared by the single and multiple boxes so the two behave identically: what
 * a search matches, and how many rows are drawn before the rest are counted
 * rather than rendered.
 */

/**
 * Rows drawn at once.
 *
 * Reka's own combobox filter keeps every item mounted and hides the ones that
 * miss, and the catalogue behind these boxes runs to well over a thousand -
 * past that a list takes long enough to open to feel broken. The boxes pass
 * `ignore-filter` and narrow through here instead.
 */
export const MAX_VISIBLE_OPTIONS = 50;

/** An option, plus the extra text a caller wants searched but not shown. */
type Searchable = App.Data.OptionData & { keywords?: string | null };

/**
 * Whether an option answers to a search term.
 *
 * The label and the hint are searched: a customer is looked up by their phone
 * number as often as by their name, and a product by its SKU as often as by
 * what it is called. `keywords` is for what identifies a record without
 * belonging on the row - the company a customer came in for, which is how they
 * are remembered even though the box asks for a person.
 */
function matches(option: Searchable, term: string): boolean {
    return `${option.label} ${option.hint ?? ''} ${option.keywords ?? ''}`
        .toLowerCase()
        .includes(term);
}

function normalise(search: string): string {
    return search.trim().toLowerCase();
}

/**
 * The first `limit` options matching a search term, in the order given.
 */
export function matchOptions<T extends Searchable>(
    options: T[],
    search: string,
    limit: number = MAX_VISIBLE_OPTIONS,
): T[] {
    const term = normalise(search);

    if (term === '') {
        return options.slice(0, limit);
    }

    const found: T[] = [];

    for (const option of options) {
        if (matches(option, term)) {
            found.push(option);
        }

        if (found.length >= limit) {
            break;
        }
    }

    return found;
}

/**
 * How many options match in total, including the ones past the cap.
 *
 * Counted rather than collected, so telling somebody there are four hundred
 * more does not build four hundred more objects to say it.
 */
export function countMatches(options: Searchable[], search: string): number {
    const term = normalise(search);

    if (term === '') {
        return options.length;
    }

    let count = 0;

    for (const option of options) {
        if (matches(option, term)) {
            count++;
        }
    }

    return count;
}
