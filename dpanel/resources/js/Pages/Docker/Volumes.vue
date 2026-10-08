<script setup>
import { computed, onMounted, ref } from 'vue';
import DockerShell from './components/DockerShell.vue';
import { useDocker } from './composables/useDocker';

const docker = useDocker();
const { status, loading, loadError, busy, message, act, post, get } = docker;

const volumes = ref([]);
const sizing = ref(false);
const search = ref('');
const filter = ref('all');
const newName = ref('');
const nameInput = ref(null);

const setVolumes = (data) => { volumes.value = data.volumes || []; };

const load = async () => {
    await docker.load();
    if (!status.value.running) return;
    sizing.value = true;
    try {
        setVolumes((await get('docker.volumes.list')).data.data);
    } catch (e) {
        loadError.value = docker.errorText(e);
    } finally {
        sizing.value = false;
    }
};

const filters = computed(() => [
    { key: 'all', label: 'All', count: volumes.value.length },
    { key: 'used', label: 'In use', count: volumes.value.filter((v) => v.used_by.length).length },
    { key: 'unused', label: 'Unused', count: volumes.value.filter((v) => !v.used_by.length).length },
    { key: 'anonymous', label: 'Anonymous', count: volumes.value.filter((v) => v.anonymous).length },
].filter((f) => f.key === 'all' || f.count > 0));

const rows = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return volumes.value
        .filter((v) => ({ all: true, used: v.used_by.length > 0, unused: !v.used_by.length, anonymous: v.anonymous })[filter.value] ?? true)
        .filter((v) => !needle || `${v.name} ${v.project} ${v.used_by.join(' ')}`.toLowerCase().includes(needle))
        .sort((a, b) => Number(a.anonymous) - Number(b.anonymous) || a.name.localeCompare(b.name));
});

const create = async () => {
    if (await act(() => post('docker.volumes.action', { action: 'create', name: newName.value.trim() }), 'create', setVolumes)) newName.value = '';
};
const remove = (volume) => {
    if (volume.used_by.length) return;
    const typed = window.prompt(`This deletes volume ${volume.name} and every file in it. This cannot be undone.\n\nType the volume name to confirm:`);
    if (typed?.trim() !== volume.name) return;
    act(() => post('docker.volumes.action', { action: 'remove', name: volume.name }), `remove:${volume.name}`, setVolumes);
};
const prune = (all) => {
    const text = all
        ? 'Delete EVERY volume no container uses, named ones included? Their data is gone for good.\n\nType DELETE to confirm:'
        : 'Remove unused anonymous volumes (the unnamed ones containers leave behind)? Named volumes are kept.';
    if (all ? window.prompt(text)?.trim() !== 'DELETE' : !confirm(text)) return;
    act(() => post('docker.volumes.action', { action: 'prune', all }), all ? 'prune-all' : 'prune', setVolumes);
};

const copy = (text) => navigator.clipboard?.writeText(text).catch(() => {});

onMounted(load);
</script>

<template>
    <DockerShell
        title="Docker Volumes"
        description="Where containers keep data that must survive a restart, update or recreate."
        :status="status"
        :loading="loading"
        :load-error="loadError"
        :message="message"
        :refreshing="busy === 'refresh' || sizing"
        :shortcuts="[{ keys: 'n', label: 'Create a volume' }]"
        :keys="{ n: () => nameInput?.focus() }"
        @refresh="load"
        @dismiss="message = null"
    >
        <section class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <div class="grid gap-4 md:grid-cols-[1fr_auto] md:items-end">
                <div>
                    <h2 class="text-base font-semibold">Create a volume</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Containers also create one automatically when you type a new name in their Volumes field.</p>
                </div>
                <form class="flex gap-2" @submit.prevent="create">
                    <input ref="nameInput" v-model="newName" type="text" placeholder="my-app-data" class="min-w-0 flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" aria-label="Volume name" />
                    <button type="submit" :disabled="!newName.trim() || busy === 'create'" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-40">Create</button>
                </form>
            </div>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 p-4 dark:border-slate-800">
                <div class="flex flex-wrap gap-1">
                    <button v-for="f in filters" :key="f.key" type="button" class="rounded-full px-3 py-1 text-xs font-medium" :class="filter === f.key ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700'" @click="filter = f.key">
                        {{ f.label }} <span class="opacity-60">{{ f.count }}</span>
                    </button>
                </div>
                <input v-model="search" data-docker-search type="search" placeholder="Find a volume  ( / )" class="ml-auto w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm sm:w-64 dark:border-slate-700 dark:bg-slate-800" />
            </div>
            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                <li v-for="v in rows" :key="v.name" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
                    <div class="min-w-0 flex-1 basis-64">
                        <div class="flex items-center gap-2">
                            <i class="bi bi-hdd-stack text-slate-400"></i>
                            <span class="truncate font-mono text-sm" :title="v.name">{{ v.anonymous ? `${v.name.slice(0, 12)}…` : v.name }}</span>
                            <span v-if="v.anonymous" class="rounded bg-slate-100 px-1.5 text-[10px] text-slate-500 dark:bg-slate-800">anonymous</span>
                            <span v-if="v.project" class="rounded bg-violet-100 px-1.5 text-[10px] text-violet-700 dark:bg-violet-900/40 dark:text-violet-300">stack {{ v.project }}</span>
                        </div>
                        <button type="button" class="mt-0.5 truncate font-mono text-[11px] text-slate-500 hover:underline" title="Copy path on the server" @click="copy(v.mountpoint)">{{ v.mountpoint }}</button>
                    </div>
                    <div class="w-20 text-right text-sm tabular-nums">{{ v.size || (sizing ? '…' : '—') }}</div>
                    <div class="min-w-0 basis-40 text-xs">
                        <span v-if="v.used_by.length" class="text-slate-600 dark:text-slate-300"><i class="bi bi-box mr-1"></i>{{ v.used_by.join(', ') }}</span>
                        <span v-else class="text-slate-400">not used</span>
                    </div>
                    <button type="button" :disabled="v.used_by.length > 0 || busy === `remove:${v.name}`" :title="v.used_by.length ? 'In use: remove its containers first' : 'Delete volume and its data'" class="rounded-md border border-red-300 px-2 py-1 text-xs text-red-700 hover:bg-red-50 disabled:opacity-30 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950" @click="remove(v)">
                        <i class="bi bi-trash"></i>
                    </button>
                </li>
                <li v-if="!rows.length" class="px-4 py-10 text-center text-sm text-slate-500">
                    {{ loading || sizing ? 'Loading…' : (search || filter !== 'all' ? 'No volume matches.' : 'No volumes yet.') }}
                </li>
            </ul>
            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-100 p-3 dark:border-slate-800">
                <button type="button" :disabled="busy === 'prune'" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-100 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800" @click="prune(false)"><i class="bi bi-eraser mr-1"></i>Remove unused anonymous volumes</button>
                <button type="button" :disabled="busy === 'prune-all'" class="rounded-md border border-red-300 px-3 py-1.5 text-xs text-red-700 hover:bg-red-50 disabled:opacity-40 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950" @click="prune(true)"><i class="bi bi-exclamation-triangle mr-1"></i>Delete all unused volumes</button>
            </div>
        </section>
        <p class="text-xs text-slate-500">Volume files live under Docker's folder on the server, which the File Manager does not open. To edit an app's files yourself, mount a folder inside a website's home instead (e.g. <code>/home/site-user/docker/app</code>).</p>
    </DockerShell>
</template>
