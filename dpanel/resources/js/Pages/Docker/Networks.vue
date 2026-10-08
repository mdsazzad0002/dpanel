<script setup>
import { computed, onMounted, ref } from 'vue';
import DockerShell from './components/DockerShell.vue';
import NetworkCard from './components/networks/NetworkCard.vue';
import { useDocker } from './composables/useDocker';

const docker = useDocker();
const { status, loading, loadError, busy, message, act, post, get } = docker;

const networks = ref([]);
const form = ref({ name: '', internal: false, subnet: '' });
const showCreate = ref(false);
const showBuiltIn = ref(false);
const nameInput = ref(null);

const setNetworks = (data) => { networks.value = data.networks || []; };

const load = async () => {
    await docker.load();
    if (!status.value.running) return;
    try {
        setNetworks((await get('docker.networks.list')).data.data);
    } catch (e) {
        loadError.value = docker.errorText(e);
    }
};

const shown = computed(() => networks.value
    .filter((n) => showBuiltIn.value || !n.built_in || n.containers.length)
    .sort((a, b) => Number(a.built_in) - Number(b.built_in) || a.name.localeCompare(b.name)));
const hiddenCount = computed(() => networks.value.length - shown.value.length);

const openCreate = () => {
    showCreate.value = true;
    window.setTimeout(() => nameInput.value?.focus(), 50);
};

const create = async () => {
    const ok = await act(() => post('docker.networks.action', { action: 'create', ...form.value }), 'create', setNetworks);
    if (ok) {
        form.value = { name: '', internal: false, subnet: '' };
        showCreate.value = false;
        docker.load({ quiet: true });
    }
};
const connect = (name, container, aliases) => act(() => post('docker.networks.action', { action: 'connect', name, container, aliases }), `connect:${name}`, setNetworks);
const disconnect = (name, container) => {
    if (!confirm(`Take ${container} off ${name}? It can no longer reach the containers there by name.`)) return;
    act(() => post('docker.networks.action', { action: 'disconnect', name, container }), `disconnect:${name}:${container}`, setNetworks);
};
const remove = async (network) => {
    if (!confirm(`Remove network ${network.name}?`)) return;
    if (await act(() => post('docker.networks.action', { action: 'remove', name: network.name }), `remove:${network.name}`, setNetworks)) docker.load({ quiet: true });
};
const prune = () => {
    if (!confirm('Remove every network no container uses? Built-in networks stay.')) return;
    act(() => post('docker.networks.action', { action: 'prune' }), 'prune', setNetworks);
};

onMounted(load);
</script>

<template>
    <DockerShell
        title="Docker Networks"
        description="Put containers on the same network so they can reach each other by name."
        :status="status"
        :loading="loading"
        :load-error="loadError"
        :message="message"
        :refreshing="busy === 'refresh'"
        :shortcuts="[{ keys: 'n', label: 'Create a network' }]"
        :keys="{ n: openCreate }"
        @refresh="load"
        @dismiss="message = null"
    >
        <template #actions>
            <button v-if="status.running" type="button" class="rounded-md bg-indigo-600 px-3 py-2 text-xs font-medium text-white hover:bg-indigo-700" title="Shortcut: n" @click="openCreate">
                <i class="bi bi-plus-lg mr-1"></i>Create network
            </button>
        </template>

        <section class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-100">
            <h2 class="font-semibold"><i class="bi bi-lightbulb mr-1"></i>How networks work</h2>
            <ol class="mt-2 list-decimal space-y-1 pl-5 text-xs sm:text-sm">
                <li>Create a network, e.g. <code>search</code>.</li>
                <li>Add containers to it here, or pick it under "More options" when you run one.</li>
                <li>They now reach each other by container name: Kibana uses <code>http://elasticsearch:9200</code>, an app uses <code>mysql</code> as its database host.</li>
            </ol>
            <p class="mt-2 text-xs opacity-80">Nothing on a network is open to the internet unless a container publishes a public port. Compose stacks get their own network automatically.</p>
        </section>

        <form v-if="showCreate" class="rounded-xl border border-indigo-200 bg-white p-4 dark:border-indigo-900 dark:bg-slate-900" @submit.prevent="create">
            <h2 class="text-base font-semibold">Create a network</h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
                <label class="text-sm">
                    <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300">Name</span>
                    <input ref="nameInput" v-model="form.name" type="text" required placeholder="search" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300">Subnet (optional)</span>
                    <input v-model="form.subnet" type="text" placeholder="automatic, or e.g. 10.20.0.0/24" class="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm dark:border-slate-700 dark:bg-slate-800" />
                </label>
                <label class="flex items-center gap-2 self-end pb-2 text-sm" title="Containers on an internal network reach only each other, never the internet">
                    <input v-model="form.internal" type="checkbox" /> Internal only
                </label>
            </div>
            <div class="mt-3 flex justify-end gap-2">
                <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-sm dark:border-slate-700" @click="showCreate = false">Cancel</button>
                <button type="submit" :disabled="!form.name.trim() || busy === 'create'" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-40">{{ busy === 'create' ? 'Creating…' : 'Create network' }}</button>
            </div>
        </form>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <NetworkCard
                v-for="n in shown"
                :key="n.id"
                :network="n"
                :containers="status.containers"
                :busy="busy"
                @connect="connect"
                @disconnect="disconnect"
                @remove="remove"
            />
        </div>
        <p v-if="!loading && !shown.length" class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500 dark:border-slate-700">
            No networks of your own yet. <button type="button" class="text-indigo-600 hover:underline dark:text-indigo-400" @click="openCreate">Create one</button>.
        </p>

        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
            <button v-if="hiddenCount || showBuiltIn" type="button" class="hover:underline" @click="showBuiltIn = !showBuiltIn">{{ showBuiltIn ? 'Hide empty built-in networks' : `Show ${hiddenCount} empty built-in network(s)` }}</button>
            <button type="button" :disabled="busy === 'prune'" class="rounded-md border border-slate-300 px-3 py-1.5 hover:bg-slate-100 disabled:opacity-40 dark:border-slate-700 dark:hover:bg-slate-800" @click="prune"><i class="bi bi-eraser mr-1"></i>Remove unused networks</button>
        </div>
    </DockerShell>
</template>
