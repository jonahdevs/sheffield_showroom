import Swal from 'sweetalert2';

/**
 * The one place the application asks "are you sure?".
 *
 * Default SweetAlert, so every destructive button in the showroom asks the
 * same way and there is nothing to keep in step.
 */
export async function confirmDelete(
    text = "You won't be able to revert this!",
): Promise<boolean> {
    const result = await Swal.fire({
        title: 'Are you sure?',
        text,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!',
    });

    return result.isConfirmed;
}

/**
 * The same question, with one answer collected before it can go ahead: a role
 * cannot be deleted until its members have somewhere to go. Resolves to the
 * choice, or to null if they backed out.
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
