import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';

/**
 * Status and actions shared by the Containers and Images pages, so each
 * page only lays out its own cards.
 */
export function useDocker() {
    const page = usePage();
    const panelToken = computed(() => String(page.props.panel?.token || ''));
    const panelRoute = (name, params = {}) => (
        panelToken.value ? route(name, { token: panelToken.value, ...params }) : route(name, params)
    );

    const status = ref({ installed: true, running: true, version: null, containers: [], images: [] });
    const loading = ref(true);
    const loadError = ref('');
    // The container, image (or 'refresh', 'run', 'pull', 'prune') whose request is running, so only that button spins.
    const busy = ref('');
    const message = ref(null);

    const load = async () => {
        busy.value = 'refresh';
        loadError.value = '';
        try {
            const { data } = await axios.get(panelRoute('docker.status'));
            status.value = data.data;
            // Installed or removed from the shell since this page loaded: refresh the menu too.
            if (Boolean(data.data?.installed) !== Boolean(page.props.features?.docker)) {
                router.reload({ only: ['features'] });
            }
        } catch (e) {
            loadError.value = e.response?.data?.message || 'Could not load Docker status.';
        } finally {
            loading.value = false;
            busy.value = '';
        }
    };

    const errorText = (e) => {
        const errors = e.response?.data?.errors;
        if (errors) return Object.values(errors).flat().join(' ');
        return e.response?.data?.message || 'Request failed.';
    };

    // Resolves true when the change went through, so a form knows to reset.
    const act = async (request, key) => {
        busy.value = key;
        message.value = null;
        try {
            const { data } = await request();
            status.value = data.data;
            message.value = { type: 'success', text: data.message };
            return true;
        } catch (e) {
            message.value = { type: 'error', text: errorText(e) };
            return false;
        } finally {
            busy.value = '';
        }
    };

    const containerAction = (action, container) => {
        if (action === 'remove' && !confirm(`Remove container ${container.name}? It is stopped first, and anything not kept in a volume is lost.`)) return;
        if (action === 'stop' && !confirm(`Stop container ${container.name}?`)) return;
        act(() => axios.post(panelRoute('docker.containers.action'), { action, id: container.id }), container.id);
    };
    const runContainer = (spec) => act(() => axios.post(panelRoute('docker.containers.run'), spec), 'run');
    const pullImage = (image) => act(() => axios.post(panelRoute('docker.images.pull'), { image }), 'pull');
    const removeImage = (image) => {
        if (!confirm(`Remove image ${image.label}?`)) return;
        act(() => axios.delete(panelRoute('docker.images.destroy'), { data: { image: image.ref } }), image.id);
    };
    const pruneImages = () => {
        if (!confirm('Remove every untagged image layer that no container uses?')) return;
        act(() => axios.post(panelRoute('docker.images.prune')), 'prune');
    };

    const fetchLogs = async (id, lines) => {
        const { data } = await axios.post(panelRoute('docker.containers.logs'), { id, lines });
        return data.data.logs;
    };

    return {
        status, loading, loadError, busy, message, load, errorText,
        containerAction, runContainer, pullImage, removeImage, pruneImages, fetchLogs,
    };
}
