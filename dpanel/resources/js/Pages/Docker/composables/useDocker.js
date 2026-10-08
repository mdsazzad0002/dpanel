import { ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useDockerRequest } from './useDockerRequest';

/**
 * Status and container/image actions shared by the Containers, Images,
 * Overview and Templates pages, so each page only lays out its own cards.
 */
export function useDocker() {
    const page = usePage();
    const { panelRoute, busy, message, errorText, act, get, post, axios } = useDockerRequest();

    const status = ref({ installed: true, running: true, version: null, compose: true, containers: [], images: [], networks: [] });
    const loading = ref(true);
    const loadError = ref('');

    const setStatus = (data) => { status.value = data; };

    const load = async ({ quiet = false } = {}) => {
        if (!quiet) busy.value = 'refresh';
        loadError.value = '';
        try {
            const { data } = await get('docker.status');
            status.value = data.data;
            // Installed or removed from the shell since this page loaded: refresh the menu too.
            if (Boolean(data.data?.installed) !== Boolean(page.props.features?.docker)) {
                router.reload({ only: ['features'] });
            }
        } catch (e) {
            loadError.value = e.response?.data?.message || 'Could not load Docker status.';
        } finally {
            loading.value = false;
            if (!quiet) busy.value = '';
        }
    };

    const confirmText = {
        remove: (c) => `Remove container ${c.name}? It is stopped first, and anything not kept in a volume is lost.`,
        stop: (c) => `Stop container ${c.name}?`,
        kill: (c) => `Kill container ${c.name}? It gets no chance to shut down cleanly.`,
        update: (c) => `Pull the newest ${c.image} and recreate ${c.name} with the same settings? Data in volumes is kept; it is down for a few seconds.`,
    };

    const containerAction = (action, container) => {
        if (confirmText[action] && !confirm(confirmText[action](container))) return Promise.resolve(null);
        return act(() => post('docker.containers.action', { action, id: container.name || container.id }), container.id, setStatus);
    };
    const bulkAction = (action, containers) => {
        if (!containers.length) return Promise.resolve(null);
        const names = containers.map((c) => c.name).join(', ');
        if (['remove', 'stop'].includes(action) && !confirm(`${action === 'remove' ? 'Remove' : 'Stop'} ${containers.length} container(s)? ${names}`)) return Promise.resolve(null);
        return act(() => post('docker.containers.bulk', { action, ids: containers.map((c) => c.name || c.id) }), 'bulk', setStatus);
    };
    const renameContainer = (container, name) => act(() => post('docker.containers.rename', { id: container.name, name }), container.id, setStatus);
    const runContainer = (spec) => act(() => post('docker.containers.run', spec), 'run', setStatus);
    const recreateContainer = (id, spec) => act(() => post('docker.containers.recreate', { id, spec }), 'run', setStatus);
    const pruneContainers = () => {
        if (!confirm('Remove every stopped container? Their volumes are kept.')) return Promise.resolve(null);
        return act(() => post('docker.containers.prune'), 'prune-containers', setStatus);
    };
    const pullImage = (image) => act(() => post('docker.images.pull', { image }), `pull:${image}`, setStatus);
    const removeImage = (image, force = false) => {
        if (!confirm(force ? `Force-remove image ${image.label}? Containers using it keep running but cannot be recreated from it.` : `Remove image ${image.label}?`)) return Promise.resolve(null);
        return act(() => axios.delete(panelRoute('docker.images.destroy'), { data: { image: image.ref, force } }), image.id, setStatus);
    };
    const pruneImages = (all = false) => {
        const text = all
            ? 'Remove every image that no container uses, including tagged ones? They are pulled again when needed.'
            : 'Remove every untagged image layer that no container uses?';
        if (!confirm(text)) return Promise.resolve(null);
        return act(() => post('docker.images.prune', { all }), all ? 'prune-all' : 'prune', setStatus);
    };

    const fetchLogs = async (id, lines) => {
        const { data } = await post('docker.containers.logs', { id, lines });
        return data.data.logs;
    };
    const inspect = async (id) => (await post('docker.containers.inspect', { id })).data.data;
    const stats = async () => (await get('docker.containers.stats')).data.data.stats;
    const exec = async (id, command, user = '', workdir = '') => (await post('docker.containers.exec', { id, command, user, workdir })).data.data;

    return {
        status, loading, loadError, busy, message, load, errorText, panelRoute,
        containerAction, bulkAction, renameContainer, runContainer, recreateContainer, pruneContainers,
        pullImage, removeImage, pruneImages, fetchLogs, inspect, stats, exec, act, post, get,
    };
}
