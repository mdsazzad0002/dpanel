import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * Start/stop/restart/status/delete for one Node.js or Python app; shared by
 * the NodeApps and PythonApps cards so each keeps only its own layout.
 */
export function useAppProjectActions(project, panelRoute) {
    const busy = ref('');
    const live = ref(null);

    const control = (action) => {
        busy.value = action;
        router.post(panelRoute('apps.control', { project: project().id }), { action }, {
            preserveScroll: true,
            onFinish: () => { busy.value = ''; live.value = null; },
        });
    };

    const checkStatus = async () => {
        busy.value = 'status';
        try {
            const response = await fetch(panelRoute('apps.status', { project: project().id }), { headers: { Accept: 'application/json' } });
            const json = await response.json();
            live.value = json.success ? json.data : { error: json.message };
        } catch (error) {
            live.value = { error: String(error) };
        } finally {
            busy.value = '';
        }
    };

    const remove = () => {
        const p = project();
        const shares = p.shares.length ? ` It is also removed from ${p.shares.length} website share(s).` : '';
        if (!confirm(`Delete "${p.name}"? The process is stopped; files stay in place.${shares}`)) return;
        router.delete(panelRoute('apps.destroy', { project: p.id }), { preserveScroll: true });
    };

    return { busy, live, control, checkStatus, remove };
}
