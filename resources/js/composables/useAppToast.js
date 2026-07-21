import { useToast } from 'primevue/usetoast';

/**
 * The one toast pattern (spec 11 §2 rule 4). Mutation results toast through
 * these helpers — success from Inertia onSuccess/flash, errors from onError.
 */
export function useAppToast() {
    const toast = useToast();

    const show = (severity, summary, detail = null, life = 4000) => toast.add({ severity, summary, detail, life });

    return {
        success: (summary, detail) => show('success', summary, detail),
        error: (summary, detail) => show('error', summary, detail, 6000),
        warn: (summary, detail) => show('warn', summary, detail),
        info: (summary, detail) => show('info', summary, detail)
    };
}
