import Swal from 'sweetalert2';

/**
 * The one place the application asks "are you sure?".
 *
 * Default SweetAlert, so every destructive button in the showroom asks the
 * same way and there is nothing to keep in step.
 *
 * `confirmText` exists because not everything guarded this way is a deletion:
 * publishing a reward campaign is just as irreversible, and a button reading
 * "Yes, delete it!" on that dialog would be telling somebody they are about to
 * do the opposite of what they are doing.
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
