import Swal from 'sweetalert2';

/**
 * The one place the application asks "are you sure?". `confirmText` is for the
 * guarded actions that are irreversible but not deletions - publishing a
 * campaign, say - where "Yes, delete it!" would say the opposite.
 */
export async function confirmDelete(
    text = "You won't be able to revert this!",
    confirmText = 'Yes, delete it!',
): Promise<boolean> {
    const result = await Swal.fire({
        title: 'Are you sure?',
        text,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: confirmText,
    });

    return result.isConfirmed;
}

/**
 * The same question, with one answer collected first. Resolves to the choice,
 * or to null if they backed out.
 */
export async function confirmDeleteChoosing(options: {
    text: string;
    /** Value to label, in the order they should be offered. */
    choices: Record<string, string>;
    selected?: string;
    required: string;
}): Promise<string | null> {
    const result = await Swal.fire({
        title: 'Are you sure?',
        text: options.text,
        icon: 'warning',
        input: 'select',
        inputOptions: options.choices,
        inputValue: options.selected ?? '',
        inputValidator: (value) => (value ? null : options.required),
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!',
    });

    return result.isConfirmed ? String(result.value) : null;
}
