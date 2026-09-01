/**
 * Reka's own combobox filter keeps every item mounted and merely hides the
 * misses, which is unusable against a catalogue of a thousand. The boxes pass
 * `ignore-filter` and narrow through here instead, drawing this many rows.
 */
export const MAX_VISIBLE_OPTIONS = 50;

/** An option, plus the extra text a caller wants searched but not shown. */
type Searchable = App.Data.OptionData & { keywords?: string | null };

/**
 * Label, hint and `keywords` are all searched: a customer is looked up by
 * phone or by the company they came in for as often as by name.
 */
function matches(option: Searchable, term: string): boolean {
    return `${option.label} ${option.hint ?? ''} ${option.keywords ?? ''}`
        .toLowerCase()
        .includes(term);
}

function normalise(search: string): string {
    return search.trim().toLowerCase();
}

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

/** How many options match in total, including the ones past the cap. */
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
