import { useConfirm } from 'primevue/useconfirm';

/**
 * The one destructive-action confirm (spec 11 §2 rule 4), rendered by the
 * ConfirmDialog mounted in AuthenticatedLayout. Native confirm() and one-off
 * Dialogs are banned.
 *
 * const confirmDelete = useDeleteConfirm();
 * confirmDelete(`user "${user.name}"`, () => form.delete(...));
 */
export function useDeleteConfirm() {
    const confirm = useConfirm();

    return (label, accept, { header = 'Confirm deletion', verb = 'Delete' } = {}) =>
        confirm.require({
            message: `Are you sure you want to delete ${label}? This cannot be undone.`,
            header,
            icon: 'pi pi-exclamation-triangle',
            rejectProps: { label: 'Cancel', severity: 'secondary', outlined: true },
            acceptProps: { label: verb, severity: 'danger' },
            accept
        });
}
