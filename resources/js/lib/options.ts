import { ref } from 'vue';
import type { Ref } from 'vue';

/**
 * Reka's own combobox filter keeps every item mounted and merely hides the
 * misses, which is unusable against a catalogue of a thousand. The boxes pass
 * `ignore-filter` and narrow through here instead, drawing this many rows.
 */
export const MAX_VISIBLE_OPTIONS = 50;

/** One entry of a short menu, as the enums' `options()` writes it. */
export type MenuOption = { value: string; label: string };

/**
 * Which menu entry a stored value opens on, and what its Other box holds.
 *
 * `visits.purpose`, `.source`, `.department` and `customers.segment` are free
 * text and their menus are only suggestions, so a stored value matching no
 * option is one somebody typed. It must open on Other with the box filled, or
 * it falls off the form and is overwritten on the next save.
 */
export function storedChoice(
    options: MenuOption[],
    stored: string | null | undefined,
    fallback: string,
): { choice: string; other: string } {
    const typed =
        stored !== null &&
        stored !== undefined &&
        stored !== '' &&
        !options.some((option) => option.value === stored);

    return {
        choice: typed ? 'other' : (stored ?? fallback),
        other: typed ? stored : '',
    };
}

/** `storedChoice` as the pair of refs a select and its Other box bind to. */
export function openOnStored(
    options: MenuOption[],
    stored: string | null | undefined,
    fallback: string,
): { choice: Ref<string>; other: Ref<string> } {
    const opened = storedChoice(options, stored, fallback);

    return { choice: ref(opened.choice), other: ref(opened.other) };
}

/** What a menu-and-Other pair posts. */
export function chosenOption(choice: string, other: string): string {
    return choice === 'other' ? other.trim() : choice;
}

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
