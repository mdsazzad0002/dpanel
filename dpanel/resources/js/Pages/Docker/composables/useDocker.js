import { ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useDockerRequest } from './useDockerRequest';
import { i18n } from '@/i18n';

const t = (...args) => i18n.global.t(...args);

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
            loadError.value = e.response?.data?.message || t('docker.actions.loadFailed');
        } finally {
            loading.value = false;
            if (!quiet) busy.value = '';
        }
    };

    const confirmText = {
        remove: (c) => t('docker.actions.confirmRemove', { name: c.name }),
        stop: (c) => t('docker.actions.confirmStop', { name: c.name }),
        kill: (c) => t('docker.actions.confirmKill', { name: c.name }),
        update: (c) => t('docker.actions.confirmUpdate', { image: c.image, name: c.name }),
    };

    const containerAction = (action, container) => {
        if (confirmText[action] && !confirm(confirmText[action](container))) return Promise.resolve(null);
        return act(() => post('docker.containers.action', { action, id: container.name || container.id }), container.id, setStatus);
    };
    const bulkAction = (action, containers) => {
        if (!containers.length) return Promise.resolve(null);
        const names = containers.map((c) => c.name).join(', ');
        if (['remove', 'stop'].includes(action) && !confirm(action === 'remove' ? t('docker.actions.bulkRemove', { count: containers.length, names }) : t('docker.actions.bulkStop', { count: containers.length, names }))) return Promise.resolve(null);
        return act(() => post('docker.containers.bulk', { action, ids: containers.map((c) => c.name || c.id) }), 'bulk', setStatus);
    };
    const renameContainer = (container, name) => act(() => post('docker.containers.rename', { id: container.name, name }), container.id, setStatus);
    const runContainer = (spec) => act(() => post('docker.containers.run', spec), 'run', setStatus);
    const recreateContainer = (id, spec) => act(() => post('docker.containers.recreate', { id, spec }), 'run', setStatus);
    const pruneContainers = () => {
        if (!confirm(t('docker.actions.pruneContainers'))) return Promise.resolve(null);
        return act(() => post('docker.containers.prune'), 'prune-containers', setStatus);
    };
    const pullImage = (image) => act(() => post('docker.images.pull', { image }), `pull:${image}`, setStatus);
    const removeImage = (image, force = false) => {
        if (!confirm(force ? t('docker.actions.forceRemoveImage', { image: image.label }) : t('docker.actions.removeImage', { image: image.label }))) return Promise.resolve(null);
        return act(() => axios.delete(panelRoute('docker.images.destroy'), { data: { image: image.ref, force } }), image.id, setStatus);
    };
    const pruneImages = (all = false) => {
        const text = all
            ? t('docker.actions.pruneAllImages')
            : t('docker.actions.pruneImages');
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
